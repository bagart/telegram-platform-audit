<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAudit;

/**
 * Append-only audit sink contract.
 *
 * Audit is a sink, not a peer: producers write events into it, nothing reads
 * back into producers. The contract is append-only by design — there is no
 * update or delete method.
 *
 * Implementations MAY throw on infrastructure failures (DB unavailable, etc.).
 * Failure handling is the caller's responsibility via AuditFailurePolicy.
 */
interface AuditSinkContract
{
    /**
     * Append one entry to the immutable audit history.
     *
     * @throws \Throwable on infrastructure failure (caller decides policy)
     */
    public function append(AuditEntry $entry): void;
}
