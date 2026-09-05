<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAudit;

/**
 * Transport-agnostic correlation context.
 *
 * Correlation IDs come from many sources: HTTP headers, CLI signatures,
 * queue job IDs, daemon tick IDs, Telegram update IDs. This interface
 * abstracts the source so AuditEntry never depends on HTTP or any
 * specific transport.
 */
interface CorrelationContext
{
    /**
     * Return the correlation ID, or null if none is available.
     */
    public function id(): ?string;
}
