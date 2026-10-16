<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('notifications')) {
            Schema::create('notifications', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->string('type');
                $t->morphs('notifiable');
                $t->text('data');
                $t->timestamp('read_at')->nullable();
                $t->timestamps();
            });
        }
        Schema::table('users', fn (Blueprint $t) => $t->boolean('marketplace_email_notifications')->default(true));
        Schema::create('marketplace_notice_outbox', function (Blueprint $t) {
            $t->id();
            $t->uuid('notification_uuid')->unique();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->string('event_key', 191);
            $t->string('kind', 48);
            $t->json('data');
            $t->boolean('database_enabled');
            $t->boolean('email_enabled');
            $t->timestamp('database_delivered_at')->nullable();
            $t->timestamp('email_delivered_at')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->timestamp('processing_at')->nullable();
            $t->timestamp('last_enqueued_at')->nullable();
            $t->unsignedInteger('attempts')->default(0);
            $t->timestamps();
            $t->unique(['event_key', 'user_id']);
            $t->index(['completed_at', 'processing_at', 'id'], 'marketplace_notice_dispatch');
        });
        Schema::table('vendor_orders', function (Blueprint $t) {
            $t->string('carrier', 120)->nullable();
            $t->string('tracking_reference', 191)->nullable();
            $t->timestamp('shipped_at')->nullable();
            $t->timestamp('ready_for_pickup_at')->nullable();
            $t->timestamp('completed_at')->nullable();
        });
        Schema::create('vendor_order_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('vendor_order_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->string('from_status', 32);
            $t->string('to_status', 32);
            $t->timestamp('created_at');
        });
        Schema::create('marketplace_refunds', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('vendor_order_id')->unique()->constrained()->restrictOnDelete();
            $t->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $t->bigInteger('amount_cents');
            $t->char('currency', 3)->default('EUR');
            $t->string('status', 32)->default('refunded');
            $t->boolean('simulated')->default(true);
            $t->text('reason');
            $t->timestamp('created_at');
        });
        foreach (['vendor_order_events', 'marketplace_refunds'] as $table) {
            foreach (['UPDATE', 'DELETE'] as $op) {
                if (DB::getDriverName() === 'mysql') {
                    DB::unprepared("CREATE TRIGGER {$table}_immutable_$op BEFORE $op ON $table FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Historical marketplace record'");
                } else {
                    DB::unprepared("CREATE TRIGGER {$table}_immutable_$op BEFORE $op ON $table BEGIN SELECT RAISE(ABORT,'Historical marketplace record'); END");
                }
            }
        }
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE marketplace_refunds ADD CONSTRAINT marketplace_refund_amount CHECK (amount_cents >= 0 AND currency = 'EUR' AND status = 'refunded')");
        } else {
            DB::unprepared("CREATE TRIGGER marketplace_refund_amount_INSERT BEFORE INSERT ON marketplace_refunds WHEN NEW.amount_cents < 0 OR typeof(NEW.amount_cents) <> 'integer' OR NEW.currency <> 'EUR' OR NEW.status <> 'refunded' BEGIN SELECT RAISE(ABORT,'Invalid marketplace refund'); END");
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Private marketplace histories are retained; roll back application code only.');
    }
};
