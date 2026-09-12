<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAudit;

/**
 * Simple correlation context holding a pre-set ID.
 *
 * Used as the default binding. HTTP middleware, CLI commands, and queue
 * workers create an instance with the appropriate ID and bind it into
 * the container for the duration of the request/job.
 *
 * For mutable contexts (e.g., HTTP middleware), use MutableCorrelationContext.
 */
final class StaticCorrelationContext implements CorrelationContext
{
    public function __construct(
        private readonly ?string $correlationId = null,
    ) {
    }

    public function id(): ?string
    {
        return $this->correlationId;
    }
}
