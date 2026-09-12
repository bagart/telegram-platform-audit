<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAudit\Laravel;

use BAGArt\TelegramBotAudit\AuditQueryContract;
use BAGArt\TelegramBotAudit\AuditQueryFilter;

/**
 * Audit health probe. Checks the sink is writable by counting recent entries.
 * Returns probe result as an array suitable for platform health aggregation.
 */
final readonly class AuditHealthProbe
{
    public function __construct(
        private AuditQueryContract $query,
    ) {}

    /**
     * @return array{status: string, entries_total: int, entries_last_hour: int, entries_last_day: int, driver: string}
     */
    public function check(): array
    {
        $total = $this->query->count(new AuditQueryFilter());

        $lastHour = $this->query->count(new AuditQueryFilter(
            after: (new \DateTimeImmutable('-1 hour'))->format(\DateTimeImmutable::ATOM),
        ));

        $lastDay = $this->query->count(new AuditQueryFilter(
            after: (new \DateTimeImmutable('-1 day'))->format(\DateTimeImmutable::ATOM),
        ));

        $driver = config('audit.driver', 'unknown');

        $status = 'healthy';

        if ($lastHour === 0 && $total > 0) {
            $status = 'degraded';
        }

        return [
            'status' => $status,
            'entries_total' => $total,
            'entries_last_hour' => $lastHour,
            'entries_last_day' => $lastDay,
            'driver' => $driver,
        ];
    }
}
