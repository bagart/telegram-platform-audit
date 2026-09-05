<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAudit\Laravel;

use Illuminate\Support\Facades\DB;

/**
 * Deletes audit entries older than the configured retention period.
 *
 * Dry-run mode returns the count without actually deleting.
 */
final class RetentionPruner
{
    public function __construct(
        private readonly ?string $connection = null,
        private readonly string $table = 'audit_entries',
    ) {
    }

    /**
     * Prune entries older than the given number of days.
     *
     * @return int  Number of entries deleted (or that would be deleted in dry-run mode).
     */
    public function prune(int $days, bool $dryRun = false): int
    {
        $cutoff = now()->subDays($days);
        $query = DB::connection($this->connection)
            ->table($this->table)
            ->where('occurred_at', '<', $cutoff);

        $count = $query->count();

        if (! $dryRun && $count > 0) {
            DB::connection($this->connection)
                ->table($this->table)
                ->where('occurred_at', '<', $cutoff)
                ->delete();
        }

        return $count;
    }
}
