<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAudit\Tests\Unit;

use BAGArt\TelegramBotAudit\AuditActor;
use BAGArt\TelegramBotAudit\AuditEntry;
use BAGArt\TelegramBotAudit\AuditFailurePolicy;
use BAGArt\TelegramBotAudit\AuditTarget;
use BAGArt\TelegramBotAudit\DefaultAuditFailurePolicyResolver;
use PHPUnit\Framework\TestCase;

final class DefaultAuditFailurePolicyResolverTest extends TestCase
{
    private DefaultAuditFailurePolicyResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new DefaultAuditFailurePolicyResolver();
    }

    public function test_access_operations_are_fail_closed(): void
    {
        $entry = $this->entry('access.grant.created');
        self::assertSame(AuditFailurePolicy::FailClosed, $this->resolver->resolve($entry));
    }

    public function test_bot_operations_are_fail_closed(): void
    {
        $entry = $this->entry('bot.created');
        self::assertSame(AuditFailurePolicy::FailClosed, $this->resolver->resolve($entry));

        $entry = $this->entry('bot.token.rotated');
        self::assertSame(AuditFailurePolicy::FailClosed, $this->resolver->resolve($entry));
    }

    public function test_role_operations_are_fail_closed(): void
    {
        $entry = $this->entry('role.granted');
        self::assertSame(AuditFailurePolicy::FailClosed, $this->resolver->resolve($entry));
    }

    public function test_runtime_operations_are_fail_open(): void
    {
        $entry = $this->entry('module.runtime.failed');
        self::assertSame(AuditFailurePolicy::FailOpen, $this->resolver->resolve($entry));

        $entry = $this->entry('module.runtime.recovered');
        self::assertSame(AuditFailurePolicy::FailOpen, $this->resolver->resolve($entry));
    }

    public function test_unknown_operations_use_default_policy(): void
    {
        $entry = $this->entry('custom.operation');
        self::assertSame(AuditFailurePolicy::FailOpen, $this->resolver->resolve($entry));
    }

    public function test_exact_override_takes_precedence(): void
    {
        $resolver = new DefaultAuditFailurePolicyResolver(
            operationOverrides: ['module.enabled' => 'fail_closed'],
        );

        $entry = $this->entry('module.enabled');
        self::assertSame(AuditFailurePolicy::FailClosed, $resolver->resolve($entry));
    }

    public function test_custom_default_policy(): void
    {
        $resolver = new DefaultAuditFailurePolicyResolver(
            defaultPolicy: AuditFailurePolicy::FailClosed->value,
        );

        $entry = $this->entry('unknown.operation');
        self::assertSame(AuditFailurePolicy::FailClosed, $resolver->resolve($entry));
    }

    private function entry(string $operation): AuditEntry
    {
        return new AuditEntry(
            id: 'test',
            actor: new AuditActor(AuditActor::TYPE_SYSTEM, 'test'),
            target: new AuditTarget('bot-1', 'module', 'test'),
            operation: $operation,
            oldState: null,
            newState: null,
            source: 'test',
            occurredAt: '2026-01-01T00:00:00+00:00',
        );
    }
}
