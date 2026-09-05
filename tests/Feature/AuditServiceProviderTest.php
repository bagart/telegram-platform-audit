<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAudit\Tests\Feature;

use BAGArt\TelegramBotAudit\AuditFailurePolicyResolver;
use BAGArt\TelegramBotAudit\AuditQueryContract;
use BAGArt\TelegramBotAudit\AuditSinkContract;
use BAGArt\TelegramBotAudit\CorrelationContext;
use BAGArt\TelegramBotAudit\DefaultAuditFailurePolicyResolver;
use BAGArt\TelegramBotAudit\Laravel\DatabaseAuditQuery;
use BAGArt\TelegramBotAudit\Laravel\DatabaseAuditSink;
use BAGArt\TelegramBotAudit\StaticCorrelationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AuditServiceProviderTest extends TestCase
{
    use RefreshDatabase;

    public function test_bind_audit_sink(): void
    {
        $sink = $this->app->make(AuditSinkContract::class);

        self::assertInstanceOf(DatabaseAuditSink::class, $sink);
    }

    public function test_bind_audit_query(): void
    {
        $query = $this->app->make(AuditQueryContract::class);

        self::assertInstanceOf(DatabaseAuditQuery::class, $query);
    }

    public function test_bind_correlation_context(): void
    {
        $ctx = $this->app->make(CorrelationContext::class);

        self::assertInstanceOf(StaticCorrelationContext::class, $ctx);
    }

    public function test_bind_failure_policy_resolver(): void
    {
        $resolver = $this->app->make(AuditFailurePolicyResolver::class);

        self::assertInstanceOf(DefaultAuditFailurePolicyResolver::class, $resolver);
    }

    public function test_config_is_merged(): void
    {
        $driver = config('audit.driver');

        self::assertSame('database', $driver);
    }

    public function test_singleton_returns_same_instance(): void
    {
        $first = $this->app->make(AuditSinkContract::class);
        $second = $this->app->make(AuditSinkContract::class);

        self::assertSame($first, $second);
    }
}
