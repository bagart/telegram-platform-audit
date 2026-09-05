<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAudit\Laravel;

use BAGArt\TelegramBotAudit\AuditEntry;
use BAGArt\TelegramBotAudit\AuditSinkContract;
use Illuminate\Support\Facades\DB;

/**
 * Database-backed append-only audit sink.
 *
 * Persists audit entries to the audit_entries table via Query Builder.
 * Phase 3 will add full implementation with proper migration.
 */
final class DatabaseAuditSink implements AuditSinkContract
{
    public function __construct(
        private readonly ?string $connection = null,
        private readonly string $table = 'audit_entries',
    ) {
    }

    public function append(AuditEntry $entry): void
    {
        DB::connection($this->connection)
            ->table($this->table)
            ->insert([
                'id' => $entry->id,
                'actor_type' => $entry->actor->type,
                'actor_id' => $entry->actor->id,
                'actor_display_name' => $entry->actor->displayName,
                'bot_id' => $entry->target->botId,
                'chat_id' => $entry->target->chatId,
                'subject_type' => $entry->target->subjectType,
                'subject_id' => $entry->target->subjectId,
                'operation' => $entry->operationString(),
                'old_state' => $entry->oldState !== null ? json_encode($entry->oldState, JSON_THROW_ON_ERROR) : null,
                'new_state' => $entry->newState !== null ? json_encode($entry->newState, JSON_THROW_ON_ERROR) : null,
                'source' => $entry->source,
                'source_version' => $entry->sourceVersion,
                'metadata' => $entry->metadata !== null ? json_encode($entry->metadata, JSON_THROW_ON_ERROR) : null,
                'correlation_id' => $entry->correlationId,
                'schema_version' => AuditEntry::SCHEMA_VERSION,
                'occurred_at' => $entry->occurredAt,
                'created_at' => now(),
            ]);
    }
}
