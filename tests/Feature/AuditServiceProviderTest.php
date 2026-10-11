<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAudit\Tests\Feature;

use BAGArt\TelegramBot\Contracts\Modules\ModuleAuditEventContract;
use BAGArt\TelegramBot\Modules\TgAuditEventRegistry;
use BAGArt\TelegramBotAudit\AuditFailurePolicyResolver;
use BAGArt\TelegramBotAudit\AuditQueryContract;
use BAGArt\TelegramBotAudit\AuditSinkContract;
use BAGArt\TelegramBotAudit\CorrelationContext;
use BAGArt\TelegramBotAudit\DefaultAuditFailurePolicyResolver;
use BAGArt\TelegramBotAudit\Laravel\AuditCounters;
use BAGArt\TelegramBotAudit\Laravel\AuditServiceProvider;
use BAGArt\TelegramBotAudit\Laravel\CountingAuditSink;
use BAGArt\TelegramBotAudit\Laravel\DatabaseAuditQuery;
use BAGArt\TelegramBotAudit\MutableCorrelationContext;
use Illuminate\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Events\Dispatcher as EventDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

final class AuditServiceProviderTest extends TestCase
{
    use RefreshDatabase;

    public function test_bind_audit_sink(): void
    {
        $sink = $this->app->make(AuditSinkContract::class);

        self::assertInstanceOf(CountingAuditSink::class, $sink);
    }

    public function test_bind_audit_query(): void
    {
        $query = $this->app->make(AuditQueryContract::class);

        self::assertInstanceOf(DatabaseAuditQuery::class, $query);
    }

    public function test_bind_correlation_context(): void
    {
        $ctx = $this->app->make(CorrelationContext::class);

        self::assertInstanceOf(MutableCorrelationContext::class, $ctx);
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

    public function test_bind_audit_counters(): void
    {
        $counters = $this->app->make(AuditCounters::class);

        self::assertInstanceOf(AuditCounters::class, $counters);
    }

    public function test_platform_event_table_maps_every_platform_listener(): void
    {
        $const = (new ReflectionClass(AuditServiceProvider::class))
            ->getReflectionConstant('PLATFORM_EVENT_LISTENERS');

        self::assertNotNull($const);

        /** @var array<class-string, string> $table */
        $table = $const->getValue();

        self::assertCount(8, $table);

        $events = $this->app->make(Dispatcher::class);

        foreach ($table as $eventClass => $listener) {
            self::assertStringContainsString('@', $listener);
            self::assertTrue(
                $events->hasListeners($eventClass),
                "Platform listener missing for {$eventClass}",
            );
        }
    }

    public function test_registry_pass_registers_contract_implementing_event(): void
    {
        $events = $this->registryPass(
            static function (TgAuditEventRegistry $registry): void {
                $registry->add('probe', ProbeAuditEvent::class);
            },
        );

        self::assertCount(1, $events->getListeners(ProbeAuditEvent::class));
    }

    public function test_registry_pass_drops_class_without_contract(): void
    {
        Log::spy();

        $events = $this->registryPass(
            static function (TgAuditEventRegistry $registry): void {
                $registry->add('probe', NotAContractProbeEvent::class);
            },
        );

        self::assertCount(0, $events->getListeners(NotAContractProbeEvent::class));
        Log::shouldHaveReceived('error')
            ->withArgs(static fn (string $message): bool => str_contains($message, 'contract not implemented'))
            ->once();
    }

    public function test_registry_pass_drops_ownership_stamp_mismatch(): void
    {
        Log::spy();

        $events = $this->registryPass(
            static function (TgAuditEventRegistry $registry): void {
                $registry->add('other-module', ProbeAuditEvent::class);
            },
        );

        self::assertCount(0, $events->getListeners(ProbeAuditEvent::class));
        Log::shouldHaveReceived('warning')
            ->withArgs(static fn (string $message): bool => str_contains($message, 'stamp does not match'))
            ->once();
    }

    public function test_registry_pass_duplicate_registration_keeps_first_winner(): void
    {
        Log::spy();

        $events = $this->registryPass(static function (TgAuditEventRegistry $registry): void {
            $registry->add('probe', ProbeAuditEvent::class);
            $registry->add('probe', ProbeAuditEvent::class);
        });

        self::assertCount(1, $events->getListeners(ProbeAuditEvent::class));
        Log::shouldHaveReceived('warning')
            ->withArgs(static fn (string $message): bool => str_contains($message, 'first registration wins'))
            ->once();
    }

    public function test_registry_pass_skips_when_registry_unbound(): void
    {
        $events = new EventDispatcher();

        (new ReflectionMethod(AuditServiceProvider::class, 'registerModuleAuditEventListeners'))
            ->invoke(new AuditServiceProvider(new Container()), $events);

        self::assertCount(0, $events->getListeners(ProbeAuditEvent::class));
    }

    /**
     * Runs the private registry pass against a fresh dispatcher and a fresh,
     * test-declared registry — the booted app's registrations stay untouched.
     *
     * @param  callable(TgAuditEventRegistry): void  $declare
     */
    private function registryPass(callable $declare): EventDispatcher
    {
        $registry = new TgAuditEventRegistry();
        $declare($registry);
        $this->app->instance(TgAuditEventRegistry::class, $registry);

        $events = new EventDispatcher();

        (new ReflectionMethod(AuditServiceProvider::class, 'registerModuleAuditEventListeners'))
            ->invoke(new AuditServiceProvider($this->app), $events);

        return $events;
    }
}

/**
 * Registry probe: contract-implementing, module id is a method constant so
 * newInstanceWithoutConstructor()-based ownership checks work.
 */
final class ProbeAuditEvent implements ModuleAuditEventContract
{
    public function moduleId(): string
    {
        return 'probe';
    }

    public function operation(): string
    {
        return 'probe.op';
    }

    public function botId(): ?string
    {
        return null;
    }

    public function subjectType(): ?string
    {
        return null;
    }

    public function subjectId(): ?string
    {
        return null;
    }

    public function actorId(): ?string
    {
        return null;
    }

    public function actorType(): ?string
    {
        return null;
    }

    public function oldState(): ?array
    {
        return null;
    }

    public function newState(): ?array
    {
        return null;
    }

    public function context(): array
    {
        return [];
    }
}

/**
 * Registry probe: deliberately does not implement ModuleAuditEventContract.
 */
final class NotAContractProbeEvent
{
}
