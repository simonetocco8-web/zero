<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exchanges', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->uuid('request_key')->unique();
            $t->char('request_hash', 64);
            $t->foreignId('retailer_a_id')->constrained('retailers')->restrictOnDelete();
            $t->foreignId('retailer_b_id')->constrained('retailers')->restrictOnDelete();
            $t->foreignId('requested_item_id')->constrained('inventory_items')->restrictOnDelete();
            $t->unsignedBigInteger('current_version_id')->nullable();
            $t->string('status', 32)->default('proposed');
            $t->timestamp('expires_at');
            $t->timestamps();
            $t->index(['retailer_a_id', 'status', 'id']);
            $t->index(['retailer_b_id', 'status', 'id']);
            $t->index(['status', 'expires_at']);
        });
        Schema::create('exchange_versions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('exchange_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('number');
            $t->foreignId('proposed_by')->constrained('retailers')->restrictOnDelete();
            $t->uuid('request_key')->unique();
            $t->char('request_hash', 64);
            $t->char('summary_hash', 64);
            $t->char('currency', 3)->default('EUR');
            $t->bigInteger('value_a_cents');
            $t->bigInteger('value_b_cents');
            $t->bigInteger('difference_cents');
            $t->foreignId('payer_retailer_id')->nullable()->constrained('retailers')->restrictOnDelete();
            $t->foreignId('payee_retailer_id')->nullable()->constrained('retailers')->restrictOnDelete();
            $t->json('parties_snapshot');
            $t->json('delivery_a');
            $t->json('delivery_b');
            $t->json('tax_snapshot');
            $t->json('commission_snapshot');
            $t->timestamp('sealed_at')->nullable();
            $t->boolean('payment_provisional');
            $t->text('notes')->nullable();
            $t->timestamp('expires_at');
            $t->timestamp('created_at');
            $t->unique(['exchange_id', 'number']);
        });
        Schema::create('exchange_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('exchange_version_id')->constrained()->restrictOnDelete();
            $t->foreignId('inventory_item_id')->constrained()->restrictOnDelete();
            $t->foreignId('retailer_id')->constrained()->restrictOnDelete();
            $t->char('side', 1);
            $t->bigInteger('quantity_milliunits');
            $t->bigInteger('agreed_unit_value_cents');
            $t->bigInteger('goods_value_cents');
            $t->json('product_snapshot');
            $t->timestamp('created_at');
            $t->unique(['exchange_version_id', 'inventory_item_id']);
        });
        foreach (['exchange_acceptances', 'exchange_completions'] as $table) {
            Schema::create($table, function (Blueprint $t) {
                $t->id();
                $t->foreignId('exchange_version_id')->constrained()->restrictOnDelete();
                $t->foreignId('retailer_id')->constrained()->restrictOnDelete();
                $t->foreignId('user_id')->constrained()->restrictOnDelete();
                $t->char('summary_hash', 64);
                $t->timestamp('created_at');
                $t->unique(['exchange_version_id', 'retailer_id']);
            });
        }
        Schema::create('exchange_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('exchange_id')->constrained()->restrictOnDelete();
            $t->foreignId('exchange_version_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('action', 48);
            $t->timestamp('created_at');
            $t->index(['exchange_id', 'id']);
        });
        Schema::create('exchange_difference_payments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('exchange_version_id')->unique()->constrained()->restrictOnDelete();
            $t->string('provider', 32)->default('fake_marketplace');
            $t->string('reference', 191)->nullable();
            $t->string('status', 32)->default('payment_pending');
            $t->bigInteger('amount_cents');
            $t->char('currency', 3)->default('EUR');
            $t->boolean('simulated')->default(true);
            $t->timestamps();
            $t->unique(['provider', 'reference']);
        });
        Schema::create('exchange_payment_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('exchange_difference_payment_id')->constrained()->restrictOnDelete();
            $t->string('provider', 32);
            $t->string('event_id', 191);
            $t->char('payload_hash', 64);
            $t->string('outcome', 32);
            $t->timestamp('created_at');
            $t->unique(['provider', 'event_id']);
        });
        // Native additive ALTER preserves SQLite's existing stock validation triggers.
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('ALTER TABLE stock_reservations ADD COLUMN exchange_version_id INTEGER NULL REFERENCES exchange_versions(id) ON DELETE RESTRICT');
        } else {
            Schema::table('stock_reservations', function (Blueprint $t) {
                $t->foreignId('exchange_version_id')->nullable()->constrained()->restrictOnDelete();
                $t->foreign('exchange_id')->references('id')->on('exchanges')->restrictOnDelete();
            });
        }
        Schema::table('stock_reservations', fn (Blueprint $t) => $t->unique(['exchange_version_id', 'inventory_item_id'], 'stock_exchange_version_item'));
        $rules = [
            'exchanges' => ['retailer_a_id <> retailer_b_id', "status IN ('proposed','countered','awaiting_acceptance','accepted','awaiting_difference_payment','confirmed','rejected','cancelled','expired','completed')"],
            'exchange_versions' => ['number > 0', 'value_a_cents >= 0 AND value_b_cents >= 0', 'difference_cents = ABS(value_a_cents - value_b_cents)', '(difference_cents = 0 AND payer_retailer_id IS NULL AND payee_retailer_id IS NULL) OR (difference_cents > 0 AND payer_retailer_id IS NOT NULL AND payee_retailer_id IS NOT NULL AND payer_retailer_id <> payee_retailer_id)'],
            'exchange_lines' => ["side IN ('a','b')", 'quantity_milliunits > 0 AND agreed_unit_value_cents >= 0 AND goods_value_cents >= 0'],
            'exchange_difference_payments' => ['amount_cents > 0', "status IN ('payment_pending','paid','failed','cancelled','expired')"],
            'stock_reservations' => ['(exchange_id IS NULL AND exchange_version_id IS NULL) OR (exchange_id IS NOT NULL AND exchange_version_id IS NOT NULL AND order_id IS NULL)'],
        ];
        foreach ($rules as $table => $predicates) {
            foreach ($predicates as $i => $rule) {
                if (DB::getDriverName() === 'mysql') {
                    DB::statement("ALTER TABLE $table ADD CONSTRAINT {$table}_exchange_rule_$i CHECK ($rule)");
                } else {
                    $columns = implode('|', Schema::getColumnListing($table));
                    $rule = preg_replace('/\b('.$columns.')\b/', 'NEW.$1', $rule);
                    foreach (['INSERT', 'UPDATE'] as $op) {
                        DB::unprepared("CREATE TRIGGER {$table}_exchange_rule_{$i}_$op BEFORE $op ON $table WHEN NOT ($rule) BEGIN SELECT RAISE(ABORT,'Invalid exchange data'); END");
                    }
                }
            }
        }
        foreach (['exchange_lines', 'exchange_acceptances', 'exchange_completions', 'exchange_events', 'exchange_payment_events'] as $table) {
            foreach (['UPDATE', 'DELETE'] as $op) {
                if (DB::getDriverName() === 'mysql') {
                    DB::unprepared("CREATE TRIGGER {$table}_immutable_$op BEFORE $op ON $table FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Immutable exchange record'");
                } else {
                    DB::unprepared("CREATE TRIGGER {$table}_immutable_$op BEFORE $op ON $table BEGIN SELECT RAISE(ABORT,'Immutable exchange record'); END");
                }
            }
        }
        // A version is built and sealed inside one transaction. It cannot acquire additional
        // lines later, even after a counterproposal makes it no longer current.
        $fields = array_diff(Schema::getColumnListing('exchange_versions'), ['sealed_at']);
        if (DB::getDriverName() === 'mysql') {
            $changed = implode(' OR ', array_map(fn ($c) => "NOT (NEW.$c <=> OLD.$c)", $fields));
            DB::unprepared("CREATE TRIGGER exchange_versions_seal_UPDATE BEFORE UPDATE ON exchange_versions FOR EACH ROW BEGIN IF OLD.sealed_at IS NOT NULL OR NEW.sealed_at IS NULL OR $changed THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Immutable exchange version'; END IF; END");
            DB::unprepared("CREATE TRIGGER exchange_versions_immutable_DELETE BEFORE DELETE ON exchange_versions FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Immutable exchange version'");
        } else {
            $changed = implode(' OR ', array_map(fn ($c) => "NEW.$c IS NOT OLD.$c", $fields));
            DB::unprepared("CREATE TRIGGER exchange_versions_seal_UPDATE BEFORE UPDATE ON exchange_versions WHEN OLD.sealed_at IS NOT NULL OR NEW.sealed_at IS NULL OR $changed BEGIN SELECT RAISE(ABORT,'Immutable exchange version'); END");
            DB::unprepared("CREATE TRIGGER exchange_versions_immutable_DELETE BEFORE DELETE ON exchange_versions BEGIN SELECT RAISE(ABORT,'Immutable exchange version'); END");
        }
        $insertRules = [
            'exchange_lines' => "NOT EXISTS (SELECT 1 FROM exchange_versions v JOIN exchanges e ON e.id=v.exchange_id JOIN inventory_items p ON p.id=NEW.inventory_item_id WHERE v.id=NEW.exchange_version_id AND v.sealed_at IS NULL AND p.retailer_id=NEW.retailer_id AND ((NEW.side='a' AND NEW.retailer_id=e.retailer_a_id) OR (NEW.side='b' AND NEW.retailer_id=e.retailer_b_id)))",
            'exchange_acceptances' => 'NOT EXISTS (SELECT 1 FROM exchange_versions v JOIN exchanges e ON e.id=v.exchange_id JOIN retailers r ON r.id=NEW.retailer_id WHERE v.id=NEW.exchange_version_id AND v.sealed_at IS NOT NULL AND v.summary_hash=NEW.summary_hash AND r.user_id=NEW.user_id AND r.id IN (e.retailer_a_id,e.retailer_b_id))',
            'exchange_completions' => 'NOT EXISTS (SELECT 1 FROM exchange_versions v JOIN exchanges e ON e.id=v.exchange_id JOIN retailers r ON r.id=NEW.retailer_id WHERE v.id=NEW.exchange_version_id AND v.sealed_at IS NOT NULL AND v.summary_hash=NEW.summary_hash AND r.user_id=NEW.user_id AND r.id IN (e.retailer_a_id,e.retailer_b_id))',
        ];
        foreach ($insertRules as $table => $invalid) {
            if (DB::getDriverName() === 'mysql') {
                DB::unprepared("CREATE TRIGGER {$table}_source_INSERT BEFORE INSERT ON $table FOR EACH ROW BEGIN IF $invalid THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid exchange record source'; END IF; END");
            } else {
                DB::unprepared("CREATE TRIGGER {$table}_source_INSERT BEFORE INSERT ON $table WHEN $invalid BEGIN SELECT RAISE(ABORT,'Invalid exchange record source'); END");
            }
        }
        $invalid = 'NEW.current_version_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM exchange_versions WHERE id=NEW.current_version_id AND exchange_id=NEW.id AND sealed_at IS NOT NULL)';
        if (DB::getDriverName() === 'mysql') {
            DB::unprepared("CREATE TRIGGER exchanges_current_version_UPDATE BEFORE UPDATE ON exchanges FOR EACH ROW BEGIN IF $invalid THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid current exchange version'; END IF; END");
        } else {
            DB::unprepared("CREATE TRIGGER exchanges_current_version_UPDATE BEFORE UPDATE ON exchanges WHEN $invalid BEGIN SELECT RAISE(ABORT,'Invalid current exchange version'); END");
        }
        // Verify membership without changing old reservation rows or rebuilding their table.
        foreach (['INSERT', 'UPDATE'] as $op) {
            $invalid = 'NEW.exchange_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM exchange_versions WHERE id = NEW.exchange_version_id AND exchange_id = NEW.exchange_id)';
            if (DB::getDriverName() === 'mysql') {
                DB::unprepared("CREATE TRIGGER stock_exchange_source_$op BEFORE $op ON stock_reservations FOR EACH ROW BEGIN IF $invalid THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid exchange reservation source'; END IF; END");
            } else {
                DB::unprepared("CREATE TRIGGER stock_exchange_source_$op BEFORE $op ON stock_reservations WHEN $invalid BEGIN SELECT RAISE(ABORT,'Invalid exchange reservation source'); END");
            }
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Exchange history must be retained; roll back application code only.');
    }
};
