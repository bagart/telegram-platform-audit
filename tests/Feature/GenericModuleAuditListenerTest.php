<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAudit\Tests\Feature;

use BAGArt\TelegramBot\Contracts\Modules\ModuleAuditEventContract;
use BAGArt\TelegramBotAudit\AuditEntry;
use BAGArt\TelegramBotAudit\AuditException;
use BAGArt\TelegramBotAudit\AuditSinkContract;
use BAGArt\TelegramBotAudit\DefaultAuditFailurePolicyResolver;
use BAGArt\TelegramBotAudit\InMemoryAuditSink;
use BAGArt\TelegramBotAudit\Laravel\Listeners\GenericModuleAuditListener;
use BAGArt\TelegramBotAudit\MutableCorrelationContext;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

final class GenericModuleAuditListenerTest extends TestCase
{
    private InMemoryAuditSink $sink;

    private MutableCorrelationContext $correlation;

    private GenericModuleAuditListener $listener;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sink = new InMemoryAuditSink();
        $this->correlation = new MutableCorrelationContext('corr-test-1');
        $this->listener = new GenericModuleAuditListener(
            sink: $this->sink,
            correlation: $this->correlation,
            policyResolver: new DefaultAuditFailurePolicyResolver(),
        );
    }

    public function test_records_entry_from_module_event(): void
    {
        $this->listener->handle(new ListenerProbeAuditEvent(
            operation: 'antispam.rule.created',
            moduleId: 'antispam',
            botId: '42',
            subjectType: 'antispam_rule',
            subjectId: 'r-1',
            actorId: '7',
            actorType: 'admin',
            oldState: ['pattern' => 'off'],
            newState: ['pattern' => 'flood'],
            context: ['trigger' => 'flood', 'telegram_update_id' => 99],
        ));

        $entries = $this->sink->snapshot();
        self::assertCount(1, $entries);

        $entry = $entries[0];
        self::assertSame('antispam.rule.created', $entry->operation);
        self::assertSame('antispam', $entry->source);
        self::assertSame('42', $entry->target->botId);
        self::assertSame('antispam_rule', $entry->target->subjectType);
        self::assertSame('r-1', $entry->target->subjectId);
        self::assertSame('7', $entry->actor->id);
        self::assertSame('admin', $entry->actor->type);
        self::assertSame(['pattern' => 'off'], $entry->oldState);
        self::assertSame(['pattern' => 'flood'], $entry->newState);
        self::assertSame('corr-test-1', $entry->correlationId);
    }

    public function test_defaults_to_system_actor_and_module_action_subject(): void
    {
        $this->listener->handle(new ListenerProbeAuditEvent(
            operation: 'nettools.target.remembered',
            moduleId: 'nettools',
        ));

        $entry = $this->sink->snapshot()[0];
        self::assertSame('system', $entry->actor->type);
        self::assertSame('system', $entry->actor->id);
        self::assertSame('module_action', $entry->target->subjectType);
        self::assertSame('nettools.target.remembered', $entry->target->subjectId);
    }

    public function test_context_is_passed_through_as_metadata(): void
    {
        $context = ['provider_key' => 'tok-should-not-be-filtered', 'chat_id' => -100];

        $this->listener->handle(new ListenerProbeAuditEvent(
            operation: 'tts.settings.changed',
            moduleId: 'tts',
            context: $context,
        ));

        self::assertSame($context, $this->sink->snapshot()[0]->metadata);
    }

    public function test_fail_open_sink_failure_logs_and_continues(): void
    {
        Log::spy();

        $listener = new GenericModuleAuditListener(
            sink: new ExplodingAuditSink(),
            correlation: $this->correlation,
            policyResolver: new DefaultAuditFailurePolicyResolver(),
        );

        $listener->handle(new ListenerProbeAuditEvent(
            operation: 'antispam.rule.created',
            moduleId: 'antispam',
        ));

        self::assertSame(0, $this->sink->count());
        Log::shouldHaveReceived('warning')
            ->withArgs(static fn (string $message): bool => str_contains($message, 'fail_open'))
            ->once();
    }

    public function test_fail_closed_sink_failure_throws_audit_exception(): void
    {
        $listener = new GenericModuleAuditListener(
            sink: new ExplodingAuditSink(),
            correlation: $this->correlation,
            policyResolver: new DefaultAuditFailurePolicyResolver(),
        );

        try {
            $listener->handle(new ListenerProbeAuditEvent(
                operation: 'access.grant.created',
                moduleId: 'antispam',
            ));

            self::fail('FailClosed operation must rethrow as AuditException');
        } catch (AuditException $e) {
            self::assertStringContainsString('access.grant.created', $e->getMessage());
        }
    }
}

/**
 * Test double for a module-owned audit event: constructor state drives every
 * contract accessor, so one class covers all listener branches.
 */
final class ListenerProbeAuditEvent implements ModuleAuditEventContract
{
    /**
     * @param  array<string, mixed>|null  $oldState
     * @param  array<string, mixed>|null  $newState
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly string $operation,
        public readonly string $moduleId = 'antispam',
        public readonly ?string $botId = null,
        public readonly ?string $subjectType = null,
        public readonly ?string $subjectId = null,
        public readonly ?string $actorId = null,
        public readonly ?string $actorType = null,
        public readonly ?array $oldState = null,
        public readonly ?array $newState = null,
        public readonly array $context = [],
    ) {
    }

    public function moduleId(): string
    {
        return $this->moduleId;
    }

    public function operation(): string
    {
        return $this->operation;
    }

    public function botId(): ?string
    {
        return $this->botId;
    }

    public function subjectType(): ?string
    {
        return $this->subjectType;
    }

    public function subjectId(): ?string
    {
        return $this->subjectId;
    }

    public function actorId(): ?string
    {
        return $this->actorId;
    }

    public function actorType(): ?string
    {
        return $this->actorType;
    }

    public function oldState(): ?array
    {
        return $this->oldState;
    }

    public function newState(): ?array
    {
        return $this->newState;
    }

    public function context(): array
    {
        return $this->context;
    }
}

/**
 * Sink double simulating an infrastructure failure (DB unavailable).
 */
final class ExplodingAuditSink implements AuditSinkContract
{
    public function append(AuditEntry $entry): void
    {
        throw new \RuntimeException('storage unavailable');
    }
}
