<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAudit\Tests\Unit;

use BAGArt\TelegramBotAudit\StaticCorrelationContext;
use PHPUnit\Framework\TestCase;

final class StaticCorrelationContextTest extends TestCase
{
    public function test_returns_provided_id(): void
    {
        $context = new StaticCorrelationContext('corr-123');
        self::assertSame('corr-123', $context->id());
    }

    public function test_returns_null_when_no_id(): void
    {
        $context = new StaticCorrelationContext();
        self::assertNull($context->id());
    }

    public function test_returns_null_when_null_id(): void
    {
        $context = new StaticCorrelationContext(null);
        self::assertNull($context->id());
    }
}
