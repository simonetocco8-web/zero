<?php

namespace Tests\Feature;

use App\Enums\MarketplaceNoticeKind;
use App\Jobs\DeliverMarketplaceNotice;
use App\Mail\MarketplaceNoticeMail;
use App\Models\User;
use App\Services\MarketplaceNotices;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MarketplaceNotificationQueueTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.notice_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true], 'database.default' => 'notice_test']);
        $this->artisan('migrate', ['--database' => 'notice_test', '--force' => true])->assertSuccessful();
        Mail::fake();
    }

    public function test_real_database_queue_enqueues_only_after_commit_and_worker_delivers_once(): void
    {
        $u = User::factory()->create(['role' => 'customer']);
        DB::beginTransaction();
        $n = app(MarketplaceNotices::class)->publish($u->id, 'commit', MarketplaceNoticeKind::Cancelled, '/notifiche', 1);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('notifications', 1);
        Mail::assertNothingSent();
        DB::commit();
        $this->assertDatabaseCount('jobs', 1);
        $job = Queue::connection('database')->pop('marketplace-notifications');
        $this->assertNotNull($job);
        $job->fire();
        $job->delete();
        $this->assertDatabaseCount('jobs', 0);
        Mail::assertSent(MarketplaceNoticeMail::class, 1);
        (new DeliverMarketplaceNotice($n->id))->handle();
        Mail::assertSent(MarketplaceNoticeMail::class, 1);
        $this->assertNotNull($n->fresh()->completed_at);
    }

    public function test_rollback_never_enqueues_email_or_persists_notice(): void
    {
        $u = User::factory()->create(['role' => 'customer']);
        DB::beginTransaction();
        app(MarketplaceNotices::class)->publish($u->id, 'rollback-queue', MarketplaceNoticeKind::Cancelled, '/notifiche', 1);
        DB::rollBack();
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('notifications', 0);
        $this->assertDatabaseCount('marketplace_notice_outbox', 0);
        Mail::assertNothingSent();
    }

    public function test_recovery_is_bounded_and_does_not_duplicate_database_notices(): void
    {
        $u = User::factory()->create(['role' => 'customer']);
        $n = app(MarketplaceNotices::class)->publish($u->id, 'recovery', MarketplaceNoticeKind::Cancelled, '/notifiche', 1);
        $this->assertSame(0, app(MarketplaceNotices::class)->recover());
        $this->travel(6)->minutes();
        $this->artisan('marketplace:dispatch-notifications')->assertSuccessful();
        $this->assertDatabaseCount('jobs', 2);
        $this->assertSame(0, app(MarketplaceNotices::class)->recover());
        while ($job = Queue::connection('database')->pop('marketplace-notifications')) {
            $job->fire();
            $job->delete();
        }
        Mail::assertSent(MarketplaceNoticeMail::class, 1);
        $this->assertDatabaseCount('notifications', 1);
        $this->assertSame(1, $n->fresh()->attempts);
        $n->update(['completed_at' => null, 'attempts' => 5]);
        $this->travel(6)->minutes();
        $this->assertSame(0, app(MarketplaceNotices::class)->recover());
    }

    public function test_active_processing_lease_prevents_duplicate_delivery(): void
    {
        $u = User::factory()->create(['role' => 'customer']);
        $n = app(MarketplaceNotices::class)->publish($u->id, 'lease', MarketplaceNoticeKind::Cancelled, '/notifiche', 1);
        $n->update(['processing_at' => now()]);
        (new DeliverMarketplaceNotice($n->id))->handle();
        Mail::assertNothingSent();
        $this->travel(3)->minutes();
        (new DeliverMarketplaceNotice($n->id))->handle();
        Mail::assertSent(MarketplaceNoticeMail::class, 1);
    }
}
