<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAudit;

/**
 * Mutable correlation context for HTTP middleware and other dynamic contexts.
 *
 * The correlation ID can be set after construction, allowing middleware
 * to extract it from the request and store it for the request lifecycle.
 */
final class MutableCorrelationContext implements CorrelationContext
{
    private ?string $correlationId;

    public function __construct(
        ?string $correlationId = null,
    ) {
        $this->correlationId = $correlationId;
    }

    public function id(): ?string
    {
        return $this->correlationId;
    }

    public function setId(?string $id): void
    {
        $this->correlationId = $id;
    }
}
