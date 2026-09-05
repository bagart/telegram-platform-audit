<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAudit;

/**
 * Read-only query contract for audit entries.
 *
 * Separated from AuditSinkContract (ADR-002): producers write via the sink,
 * readers query via this contract. Never the same interface.
 */
interface AuditQueryContract
{
    /**
     * Query audit entries matching the given filter.
     *
     * @return iterable<AuditEntry>
     */
    public function query(AuditQueryFilter $filter): iterable;

    /**
     * Count audit entries matching the given filter.
     */
    public function count(AuditQueryFilter $filter): int;
}
