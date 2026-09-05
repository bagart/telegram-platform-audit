<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAudit\Laravel\Console\Commands;

use BAGArt\TelegramBotAudit\Laravel\RetentionPruner;
use Illuminate\Console\Command;

/**
 * Prune old audit entries based on the configured retention period.
 *
 * Usage:
 *   php artisan audit:prune
 *   php artisan audit:prune --days=90
 *   php artisan audit:prune --dry-run
 */
final class AuditPruneCommand extends Command
{
    protected $signature = 'audit:prune {--days= : Override retention days} {--dry-run : Count without deleting}';

    protected $description = 'Prune audit entries older than the retention period';

    public function handle(RetentionPruner $pruner): int
    {
        $days = (int) ($this->option('days') ?: config('audit.retention.days', 365));
        $dryRun = $this->option('dry-run');

        $count = $pruner->prune(days: $days, dryRun: $dryRun);

        if ($dryRun) {
            $this->info("Would prune {$count} entries older than {$days} days.");
        } else {
            $this->info("Pruned {$count} entries older than {$days} days.");
        }

        return self::SUCCESS;
    }
}
