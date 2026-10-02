<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAudit\Tests\Feature;

use BAGArt\TelegramBotAudit\AuditActor;
use BAGArt\TelegramBotAudit\AuditEntry;
use BAGArt\TelegramBotAudit\AuditTarget;
use BAGArt\TelegramBotAudit\Laravel\RetentionPruner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class RetentionPrunerTest extends TestCase
{
    use RefreshDatabase;

    protected function defineEnvironment($app): void
    {
        $app['config']->set('audit.driver', 'database');
        $app['config']->set('audit.database.table', 'audit_entries');
    }

    public function test_prune_removes_old_entries(): void
    {
        $this->seedEntries();

        $pruner = $this->app->make(RetentionPruner::class);

        $count = $pruner->prune(days: 30);

        self::assertGreaterThan(0, $count);

        $remaining = DB::table('audit_entries')->count();
        self::assertSame(1, $remaining);
    }

    public function test_prune_dry_run_does_not_delete(): void
    {
        $this->seedEntries();

        $pruner = $this->app->make(RetentionPruner::class);

        $count = $pruner->prune(days: 30, dryRun: true);

        self::assertGreaterThan(0, $count);

        $remaining = DB::table('audit_entries')->count();
        self::assertGreaterThan(0, $remaining);
    }

    public function test_prune_keeps_recent_entries(): void
    {
        $this->seedEntries();

        $pruner = $this->app->make(RetentionPruner::class);

        $count = $pruner->prune(days: 365);

        self::assertSame(0, $count);

        $remaining = DB::table('audit_entries')->count();
        self::assertGreaterThan(0, $remaining);
    }

    public function test_prune_logs_warning_when_hash_chain_breaks(): void
    {
        $this->seedEntries();

        \Illuminate\Support\Facades\Log::shouldReceive('warning')
            ->once()
            ->with(
                'Audit pruning may break hash chain — run audit:verify after pruning',
                \Mockery::on(fn (array $ctx): bool => $ctx['pruned'] > 0 && $ctx['retention_days'] === 30),
            );

        $pruner = $this->app->make(RetentionPruner::class);
        $pruner->prune(days: 30);
    }

    private function seedEntries(): void
    {
        $sink = $this->app->make(\BAGArt\TelegramBotAudit\AuditSinkContract::class);

        // Old entry (90 days ago)
        $oldEntry = new AuditEntry(
            id: 'old-entry',
            actor: new AuditActor(AuditActor::TYPE_SYSTEM, 'sys'),
            target: new AuditTarget('bot-1', 'module', 'cinema'),
            operation: 'module.enabled',
            oldState: null,
            newState: null,
            source: 'engine',
            occurredAt: now()->subDays(90)->format(\DateTimeImmutable::ATOM),
        );
        $sink->append($oldEntry);

        // Recent entry (today)
        $sink->append(AuditEntry::now(
            id: 'recent-entry',
            actor: new AuditActor(AuditActor::TYPE_SYSTEM, 'sys'),
            target: new AuditTarget('bot-1', 'module', 'cinema'),
            operation: 'module.disabled',
            source: 'engine',
        ));
    }
}
