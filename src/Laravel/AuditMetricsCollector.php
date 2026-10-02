<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAudit\Laravel;

use BAGArt\TelegramBotAudit\AuditQueryContract;
use BAGArt\TelegramBotAudit\AuditQueryFilter;

/**
 * Audit metrics collector. Aggregates entry counts by operation, source,
 * and actor type for the last 24 hours. Results suitable for dashboard
 * rendering or health probe detail.
 *
 * Also exposes real-time in-memory counters via {@see counters()}.
 */
final readonly class AuditMetricsCollector
{
    public function __construct(
        private AuditQueryContract $query,
        private AuditCounters $counters,
    ) {
    }

    /**
     * @return array{
     *     by_operation: array<string, int>,
     *     by_source: array<string, int>,
     *     by_actor_type: array<string, int>,
     *     total_last_hour: int,
     *     total_last_day: int,
     * }
     */
    public function collect(): array
    {
        $entries = iterator_to_array($this->query->query(new AuditQueryFilter(
            after: (new \DateTimeImmutable('-1 day'))->format(\DateTimeImmutable::ATOM),
            limit: 1000,
        )));

        $byOperation = [];
        $bySource = [];
        $byActorType = [];
        $totalLastHour = 0;
        $totalLastDay = count($entries);

        $oneHourAgo = new \DateTimeImmutable('-1 hour');

        foreach ($entries as $entry) {
            $op = $entry->operationString();
            $byOperation[$op] = ($byOperation[$op] ?? 0) + 1;

            $bySource[$entry->source] = ($bySource[$entry->source] ?? 0) + 1;

            $byActorType[$entry->actor->type] = ($byActorType[$entry->actor->type] ?? 0) + 1;

            $occurredAt = \DateTimeImmutable::createFromFormat(\DateTimeImmutable::ATOM, $entry->occurredAt);
            if ($occurredAt !== false && $occurredAt >= $oneHourAgo) {
                $totalLastHour++;
            }
        }

        arsort($byOperation);
        arsort($bySource);
        arsort($byActorType);

        return [
            'by_operation' => $byOperation,
            'by_source' => $bySource,
            'by_actor_type' => $byActorType,
            'total_last_hour' => $totalLastHour,
            'total_last_day' => $totalLastDay,
        ];
    }

    /**
     * Real-time in-memory counters (process-scoped, reset on reboot).
     */
    public function counters(): array
    {
        return $this->counters->snapshot();
    }
}
