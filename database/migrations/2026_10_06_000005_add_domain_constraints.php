<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function rules(): array
    {
        return [
            'plans' => [
                'monthly_price_cents >= 0',
                'annual_price_cents >= 0',
                'max_items IS NULL OR max_items >= 0',
                'max_inventory_value_cents IS NULL OR max_inventory_value_cents >= 0',
                'commission_basis_points BETWEEN 0 AND 10000',
                'LENGTH(currency) = 3 AND currency = UPPER(currency)',
            ],
            'retailers' => [
                'status <> \'approved\' OR approved_at IS NOT NULL',
                'status <> \'rejected\' OR rejected_at IS NOT NULL',
            ],
            'subscriptions' => [
                'price_cents >= 0',
                'ends_at IS NULL OR starts_at IS NULL OR ends_at > starts_at',
                'status <> \'active\' OR starts_at IS NOT NULL',
                'LENGTH(currency) = 3 AND currency = UPPER(currency)',
            ],
            'inventory_items' => [
                'quantity_milliunits >= 0',
                'zero_price_cents >= 0',
                'list_price_cents IS NULL OR list_price_cents >= 0',
                '(external_provider IS NULL AND external_product_id IS NULL) OR (external_provider IS NOT NULL AND external_product_id IS NOT NULL)',
                'status NOT IN (\'published\', \'change_pending\') OR published_at IS NOT NULL',
                'LENGTH(currency) = 3 AND currency = UPPER(currency)',
            ],
            'inventory_images' => [
                'position >= 0',
            ],
            'availability_requests' => [
                'status <> \'contacted\' OR contacted_at IS NOT NULL',
                'status <> \'closed\' OR closed_at IS NOT NULL',
            ],
            'integration_events' => [
                'attempts >= 0',
                'status <> \'processed\' OR processed_at IS NOT NULL',
                'LENGTH(provider) > 0 AND LENGTH(external_event_id) > 0',
                'LENGTH(payload_hash) = 64',
            ],
            'sales' => [
                'total_cents >= 0',
                'LENGTH(provider) > 0 AND LENGTH(external_order_id) > 0',
                'LENGTH(currency) = 3 AND currency = UPPER(currency)',
            ],
            'sale_items' => [
                'quantity_milliunits > 0',
                'unit_price_cents >= 0',
                'line_total_cents >= 0',
                'commission_cents BETWEEN 0 AND line_total_cents',
                'commission_basis_points BETWEEN 0 AND 10000',
                'LENGTH(currency) = 3 AND currency = UPPER(currency)',
            ],
            'payout_requests' => [
                'amount_cents > 0',
                'status <> \'paid\' OR paid_at IS NOT NULL',
                'status <> \'rejected\' OR rejected_at IS NOT NULL',
                'LENGTH(currency) = 3 AND currency = UPPER(currency)',
            ],
            'wallet_transactions' => [
                '(type IN (\'sale_credit\', \'payout_release\', \'payout_reversal\') AND amount_cents > 0) OR (type IN (\'commission\', \'refund\', \'payout_reservation\', \'payout\') AND amount_cents < 0) OR (type = \'adjustment\' AND amount_cents <> 0)',
                'type NOT IN (\'sale_credit\', \'commission\', \'refund\') OR sale_item_id IS NOT NULL',
                'type NOT IN (\'payout_reservation\', \'payout_release\', \'payout\', \'payout_reversal\') OR payout_request_id IS NOT NULL',
                'sale_item_id IS NULL OR payout_request_id IS NULL',
                'LENGTH(idempotency_key) > 0',
                'LENGTH(currency) = 3 AND currency = UPPER(currency)',
            ],
        ];
    }

    private function integerColumns(): array
    {
        return [
            'plans' => ['monthly_price_cents', 'annual_price_cents', 'max_items', 'max_inventory_value_cents', 'commission_basis_points'],
            'subscriptions' => ['price_cents'],
            'inventory_items' => ['quantity_milliunits', 'zero_price_cents', 'list_price_cents'],
            'inventory_images' => ['position'],
            'integration_events' => ['attempts'],
            'sales' => ['total_cents'],
            'sale_items' => ['quantity_milliunits', 'unit_price_cents', 'line_total_cents', 'commission_basis_points', 'commission_cents'],
            'payout_requests' => ['amount_cents'],
            'wallet_transactions' => ['amount_cents'],
        ];
    }

    public function up(): void
    {
        foreach ($this->rules() as $table => $rules) {
            if (DB::getDriverName() === 'mysql') {
                foreach ($rules as $index => $predicate) {
                    DB::statement("ALTER TABLE `$table` ADD CONSTRAINT `{$table}_rule_$index` CHECK ($predicate)");
                }
            } elseif (DB::getDriverName() === 'sqlite') {
                $columns = implode('|', Schema::getColumnListing($table));
                $predicates = array_map(fn ($rule) => preg_replace('/\b('.$columns.')\b/', 'NEW.$1', $rule), $rules);
                foreach ($this->integerColumns()[$table] ?? [] as $column) {
                    $predicates[] = "NEW.$column IS NULL OR typeof(NEW.$column) = 'integer'";
                }
                $invalid = implode(' OR ', array_map(fn ($predicate) => "NOT ($predicate)", $predicates));
                foreach (['INSERT', 'UPDATE'] as $operation) {
                    DB::unprepared("CREATE TRIGGER {$table}_validate_$operation BEFORE $operation ON $table
                        WHEN $invalid BEGIN SELECT RAISE(ABORT, 'Invalid domain data'); END");
                }
            } else {
                throw new RuntimeException('Domain constraints support MySQL and SQLite only.');
            }
        }

        foreach (['wallet_transactions', 'audit_logs'] as $table) {
            foreach (['UPDATE', 'DELETE'] as $operation) {
                if (DB::getDriverName() === 'mysql') {
                    DB::unprepared("CREATE TRIGGER {$table}_immutable_$operation BEFORE $operation ON $table
                        FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Append-only record'");
                } else {
                    DB::unprepared("CREATE TRIGGER {$table}_immutable_$operation BEFORE $operation ON $table
                        BEGIN SELECT RAISE(ABORT, 'Append-only record'); END");
                }
            }
        }
    }

    public function down(): void
    {
        foreach (['wallet_transactions', 'audit_logs'] as $table) {
            foreach (['UPDATE', 'DELETE'] as $operation) {
                DB::unprepared("DROP TRIGGER IF EXISTS {$table}_immutable_$operation");
            }
        }
        foreach ($this->rules() as $table => $rules) {
            if (DB::getDriverName() === 'mysql') {
                foreach (array_keys($rules) as $index) {
                    DB::statement("ALTER TABLE `$table` DROP CHECK `{$table}_rule_$index`");
                }
            } else {
                foreach (['INSERT', 'UPDATE'] as $operation) {
                    DB::unprepared("DROP TRIGGER IF EXISTS {$table}_validate_$operation");
                }
            }
        }
    }
};
