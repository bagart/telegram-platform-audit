<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAudit\Laravel\Console\Commands;

use BAGArt\TelegramBotAudit\Laravel\AuditHasher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Verify the audit entry hash chain integrity.
 *
 * Walks all entries in chain order (ascending sequence — matching
 * DatabaseAuditSink::fetchPrevHash chain direction), recomputes each hash,
 * and alerts on any breaks. Does NOT crash — reports and exits.
 */
class AuditVerifyCommand extends Command
{
    protected $signature = 'audit:verify
        {--batch=1000 : Number of entries to process per batch}';

    protected $description = 'Verify tamper-evident hash chain integrity';

    public function handle(AuditHasher $hasher): int
    {
        $table = config('audit.database.table', 'audit_entries');
        $connection = config('audit.database.connection');
        $breaks = 0;
        $checked = 0;
        $expectedPrevHash = null;

        $this->info('Verifying audit hash chain...');

        $batchSize = (int) $this->option('batch');
        $offset = 0;

        do {
            $rows = DB::connection($connection)
                ->table($table)
                ->orderBy('sequence')
                ->offset($offset)
                ->limit($batchSize)
                ->get();

            foreach ($rows as $row) {
                $checked++;

                if ($row->prev_hash !== $expectedPrevHash) {
                    $breaks++;
                    $this->error("BREAK #{$breaks}: entry {$row->id} prev_hash mismatch — expected " . ($expectedPrevHash ?? 'null') . ", got " . ($row->prev_hash ?? 'null'));
                }

                $entry = $this->rowToEntry($row);
                $expectedHash = $hasher->computeHash($entry, $expectedPrevHash);

                if ($row->hash !== null && $row->hash !== $expectedHash) {
                    $breaks++;
                    $this->error("BREAK #{$breaks}: entry {$row->id} at {$row->occurred_at} — expected {$expectedHash}, got {$row->hash}");
                }

                $expectedPrevHash = $row->hash;
            }

            $offset += $batchSize;
        } while ($rows->isNotEmpty());

        if ($breaks === 0) {
            $this->info("Chain OK — {$checked} entries verified, 0 breaks.");
        } else {
            $this->error("Chain BROKEN — {$breaks} breaks found across {$checked} entries.");
        }

        return $breaks > 0 ? 1 : 0;
    }

    private function rowToEntry(object $row): \BAGArt\TelegramBotAudit\AuditEntry
    {
        return new \BAGArt\TelegramBotAudit\AuditEntry(
            id: (string) $row->id,
            actor: new \BAGArt\TelegramBotAudit\AuditActor(
                type: (string) $row->actor_type,
                id: (string) $row->actor_id,
                displayName: $row->actor_display_name ?? null,
            ),
            target: new \BAGArt\TelegramBotAudit\AuditTarget(
                botId: $row->bot_id !== null ? (string) $row->bot_id : null,
                subjectType: (string) $row->subject_type,
                subjectId: (string) $row->subject_id,
                chatId: $row->chat_id !== null ? (int) $row->chat_id : null,
            ),
            operation: (string) $row->operation,
            oldState: $row->old_state !== null ? json_decode((string) $row->old_state, true, 512, JSON_THROW_ON_ERROR) : null,
            newState: $row->new_state !== null ? json_decode((string) $row->new_state, true, 512, JSON_THROW_ON_ERROR) : null,
            source: (string) $row->source,
            occurredAt: (string) $row->occurred_at,
            correlationId: $row->correlation_id ?? null,
            sourceVersion: $row->source_version ?? null,
            metadata: $row->metadata !== null ? json_decode((string) $row->metadata, true, 512, JSON_THROW_ON_ERROR) : null,
        );
    }
}
