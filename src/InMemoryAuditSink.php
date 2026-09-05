<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAudit;

/**
 * In-memory append-only sink for tests and hosts without persistent storage.
 *
 * Entries accumulate in append order; there is intentionally no API to
 * mutate, remove or reorder them.
 */
final class InMemoryAuditSink implements AuditSinkContract
{
    /** @var list<AuditEntry> */
    private array $entries = [];

    public function append(AuditEntry $entry): void
    {
        $this->entries[] = $entry;
    }

    /**
     * Read-only snapshot of the accumulated entries, in append order.
     *
     * @return list<AuditEntry>
     */
    public function snapshot(): array
    {
        return $this->entries;
    }

    /**
     * Number of accumulated entries.
     */
    public function count(): int
    {
        return count($this->entries);
    }
}
