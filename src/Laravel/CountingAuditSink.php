<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAudit\Laravel;

use BAGArt\TelegramBotAudit\AuditEntry;
use BAGArt\TelegramBotAudit\AuditSinkContract;

/**
 * Decorator that wraps the real audit sink and records in-memory counters.
 *
 * Transparent to callers — implements the same contract. Counters are
 * process-scoped and reset on reboot. Use AuditCounters::snapshot() to read.
 */
final class CountingAuditSink implements AuditSinkContract
{
    public function __construct(
        private readonly AuditSinkContract $inner,
        private readonly AuditCounters $counters,
    ) {
    }

    public function append(AuditEntry $entry): void
    {
        $start = hrtime(true);

        try {
            $this->inner->append($entry);

            $latencyMs = (int) ((hrtime(true) - $start) / 1_000_000);
            $this->counters->recordAppend(
                operation: $entry->operationString(),
                botId: $entry->target->botId,
                latencyMs: $latencyMs,
            );
        } catch (\Throwable $e) {
            $this->counters->recordFailure(
                operation: $entry->operationString(),
            );

            throw $e;
        }
    }
}
