<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAudit\Laravel;

use BAGArt\TelegramBotAudit\AuditActor;
use BAGArt\TelegramBotAudit\AuditEntry;
use BAGArt\TelegramBotAudit\AuditQueryContract;
use BAGArt\TelegramBotAudit\AuditQueryFilter;
use BAGArt\TelegramBotAudit\AuditTarget;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Database-backed audit query implementation.
 *
 * Reads audit entries from the audit_entries table.
 */
final class DatabaseAuditQuery implements AuditQueryContract
{
    public function __construct(
        private readonly ?string $connection = null,
        private readonly string $table = 'audit_entries',
    ) {
    }

    public function query(AuditQueryFilter $filter): iterable
    {
        $query = $this->buildQuery($filter);

        $rows = $query
            ->orderByDesc('occurred_at')
            ->offset($filter->offset)
            ->limit($filter->limit)
            ->get();

        foreach ($rows as $row) {
            yield $this->toDomain($row);
        }
    }

    public function count(AuditQueryFilter $filter): int
    {
        return (int) $this->buildQuery($filter)->count();
    }

    private function buildQuery(AuditQueryFilter $filter): Builder
    {
        $query = DB::connection($this->connection)->table($this->table);

        if ($filter->id !== null) {
            $query->where('id', $filter->id);
        }

        if ($filter->botId !== null) {
            $query->where('bot_id', $filter->botId);
        }

        if ($filter->actorType !== null) {
            $query->where('actor_type', $filter->actorType);
        }

        if ($filter->actorId !== null) {
            $query->where('actor_id', $filter->actorId);
        }

        if ($filter->subjectType !== null) {
            $query->where('subject_type', $filter->subjectType);
        }

        if ($filter->subjectId !== null) {
            $query->where('subject_id', $filter->subjectId);
        }

        if ($filter->operation !== null) {
            if (str_ends_with($filter->operation, '*')) {
                $query->where('operation', 'like', str_replace('*', '%', $filter->operation));
            } else {
                $query->where('operation', $filter->operation);
            }
        }

        if ($filter->source !== null) {
            $query->where('source', $filter->source);
        }

        if ($filter->correlationId !== null) {
            $query->where('correlation_id', $filter->correlationId);
        }

        if ($filter->after !== null) {
            $query->where('occurred_at', '>=', $filter->after);
        }

        if ($filter->before !== null) {
            $query->where('occurred_at', '<=', $filter->before);
        }

        return $query;
    }

    private function toDomain(object $row): AuditEntry
    {
        return new AuditEntry(
            id: (string) $row->id,
            actor: new AuditActor(
                type: (string) $row->actor_type,
                id: (string) $row->actor_id,
                displayName: $row->actor_display_name ?? null,
            ),
            target: new AuditTarget(
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
            hash: $row->hash ?? null,
            prevHash: $row->prev_hash ?? null,
        );
    }
}
