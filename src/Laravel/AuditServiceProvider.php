<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAudit\Laravel;

use BAGArt\TelegramBot\Contracts\Modules\ModuleAuditEventContract;
use BAGArt\TelegramBot\Modules\TgAuditEventRegistry;
use BAGArt\TelegramBotAudit\AuditFailurePolicyResolver;
use BAGArt\TelegramBotAudit\AuditQueryContract;
use BAGArt\TelegramBotAudit\AuditSinkContract;
use BAGArt\TelegramBotAudit\CorrelationContext;
use BAGArt\TelegramBotAudit\DefaultAuditFailurePolicyResolver;
use BAGArt\TelegramBotAudit\InMemoryAuditSink;
use BAGArt\TelegramBotAudit\Laravel\Console\Commands\AuditPruneCommand;
use BAGArt\TelegramBotAudit\Laravel\Console\Commands\AuditVerifyCommand;
use BAGArt\TelegramBotAudit\Laravel\Listeners\GenericModuleAuditListener;
use BAGArt\TelegramBotAudit\Laravel\Listeners\RecordAccessControlEvents;
use BAGArt\TelegramBotAudit\Laravel\Listeners\RecordModuleLifecycleEvents;
use BAGArt\TelegramBotAudit\MutableCorrelationContext;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use ReflectionClass;

/**
 * Sole Laravel registration point for the audit module.
 *
 * Owns ALL bindings — AppServiceProvider and config/tg_modules.php know
 * nothing about BAGArt\TelegramBotAudit\*.
 *
 * Registers via Composer auto-discovery (listed in bootstrap/providers.php).
 */
class AuditServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../../config/audit.php',
            'audit',
        );

        $this->app->singleton(AuditCounters::class);

        $this->app->singleton(AuditHasher::class);

        $this->app->singleton(AuditSinkContract::class, function () {
            $driver = config('audit.driver', 'database');

            $inner = match ($driver) {
                'database' => new DatabaseAuditSink(
                    connection: config('audit.database.connection'),
                    table: config('audit.database.table', 'audit_entries'),
                    hasher: $this->app->make(AuditHasher::class),
                ),
                'memory' => new InMemoryAuditSink(),
                default => throw new \RuntimeException("Unknown audit driver: {$driver}"),
            };

            return new CountingAuditSink(
                inner: $inner,
                counters: $this->app->make(AuditCounters::class),
            );
        });

        $this->app->singleton(AuditQueryContract::class, function () {
            $driver = config('audit.driver', 'database');

            return match ($driver) {
                'database' => new DatabaseAuditQuery(
                    connection: config('audit.database.connection'),
                    table: config('audit.database.table', 'audit_entries'),
                ),
                default => throw new \RuntimeException("Audit query not supported for driver: {$driver}"),
            };
        });

        $this->app->singleton(CorrelationContext::class, function () {
            return new MutableCorrelationContext();
        });

        $this->app->singleton(AuditFailurePolicyResolver::class, function () {
            $overrides = config('audit.failure_policy.operations', []);
            $default = config('audit.failure_policy.default', 'fail_open');
            $prefixes = config('audit.failure_policy.prefixes', []);

            return new DefaultAuditFailurePolicyResolver(
                operationOverrides: $overrides,
                defaultPolicy: $default,
                operationPrefixes: $prefixes,
            );
        });

        $this->app->singleton(AuditHealthProbe::class, function () {
            return new AuditHealthProbe(
                query: $this->app->make(AuditQueryContract::class),
            );
        });

        $this->app->singleton(AuditMetricsCollector::class, function () {
            return new AuditMetricsCollector(
                query: $this->app->make(AuditQueryContract::class),
                counters: $this->app->make(AuditCounters::class),
            );
        });

        // Listeners
        $this->app->singleton(RecordAccessControlEvents::class, function () {
            return new RecordAccessControlEvents(
                sink: $this->app->make(AuditSinkContract::class),
                correlation: $this->app->make(CorrelationContext::class),
                policyResolver: $this->app->make(AuditFailurePolicyResolver::class),
            );
        });
        $this->app->singleton(RecordModuleLifecycleEvents::class, function () {
            return new RecordModuleLifecycleEvents(
                sink: $this->app->make(AuditSinkContract::class),
                correlation: $this->app->make(CorrelationContext::class),
                policyResolver: $this->app->make(AuditFailurePolicyResolver::class),
            );
        });
        $this->app->singleton(GenericModuleAuditListener::class, function () {
            return new GenericModuleAuditListener(
                sink: $this->app->make(AuditSinkContract::class),
                correlation: $this->app->make(CorrelationContext::class),
                policyResolver: $this->app->make(AuditFailurePolicyResolver::class),
            );
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../../config/audit.php' => config_path('audit.php'),
        ], 'audit-config');

        $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations');

        $this->loadRoutesFrom(__DIR__ . '/../../routes/web.php');

        // Event-listener mappings
        $this->app->booted(function (): void {
            $this->registerEventListeners();
        });

        if ($this->app->runningInConsole()) {
            $this->commands([AuditPruneCommand::class, AuditVerifyCommand::class]);

            // Schedule retention pruner
            $this->app->afterResolving('scheduler', function ($scheduler) {
                $scheduler->command('audit:prune')
                    ->daily()
                    ->at('03:00');
            });
        }
    }

    /**
     * Platform event class => listener mapping. Kept byte-for-byte so the
     * C9 parity canary (`event:list`) still lists every platform listener.
     *
     * @var array<class-string, string>
     */
    private const array PLATFORM_EVENT_LISTENERS = [
        \BAGArt\TelegramBotAccess\Events\GrantCreated::class => RecordAccessControlEvents::class . '@handleGrantCreated',
        \BAGArt\TelegramBotAccess\Events\GrantRevoked::class => RecordAccessControlEvents::class . '@handleGrantRevoked',
        \BAGArt\TelegramBotManagement\Events\BotCreated::class => RecordModuleLifecycleEvents::class . '@handleBotCreated',
        \BAGArt\TelegramBotManagement\Events\BotDeleted::class => RecordModuleLifecycleEvents::class . '@handleBotDeleted',
        \BAGArt\TelegramBotManagement\Events\BotTokenRotated::class => RecordModuleLifecycleEvents::class . '@handleBotTokenRotated',
        \BAGArt\TelegramModuleEngine\Events\BotModuleEnabled::class => RecordModuleLifecycleEvents::class . '@handleBotModuleEnabled',
        \BAGArt\TelegramModuleEngine\Events\BotModuleDisabled::class => RecordModuleLifecycleEvents::class . '@handleBotModuleDisabled',
        \BAGArt\TelegramBotManagement\Events\BotModuleSettingChanged::class => RecordModuleLifecycleEvents::class . '@handleBotModuleSettingChanged',
    ];

    private function registerEventListeners(): void
    {
        $events = $this->app->make(Dispatcher::class);

        foreach (self::PLATFORM_EVENT_LISTENERS as $eventClass => $listener) {
            $events->listen($eventClass, $listener);
        }

        $this->registerModuleAuditEventListeners($events);
    }

    /**
     * Registry pass over module-declared audit events. Runs inside the
     * app->booted() callback, i.e. after every module register() has
     * populated the registry — the registry is complete by construction.
     * Never throws: invalid entries are dropped and logged.
     */
    private function registerModuleAuditEventListeners(Dispatcher $events): void
    {
        if (! $this->app->bound(TgAuditEventRegistry::class)) {
            return;
        }

        $registry = $this->app->make(TgAuditEventRegistry::class);
        $listened = [];

        foreach ($registry->all() as $entry) {
            $class = $entry['class'];

            if (! is_a($class, ModuleAuditEventContract::class, true)) {
                Log::error('Module audit event dropped: contract not implemented', [
                    'module' => $entry['module'],
                    'class' => $class,
                ]);

                continue;
            }

            if (! $this->ownedBy($class, $entry['module'])) {
                Log::warning('Module audit event dropped: registry stamp does not match event module id', [
                    'module' => $entry['module'],
                    'class' => $class,
                ]);

                continue;
            }

            if (isset($listened[$class])) {
                Log::warning('Duplicate module audit event: first registration wins', [
                    'module' => $entry['module'],
                    'class' => $class,
                ]);

                continue;
            }

            $listened[$class] = true;
            $events->listen($class, GenericModuleAuditListener::class . '@handle');
        }
    }

    /**
     * @param  class-string<ModuleAuditEventContract>  $class
     */
    private function ownedBy(string $class, string $moduleId): bool
    {
        try {
            return (new ReflectionClass($class))->newInstanceWithoutConstructor()->moduleId() === $moduleId;
        } catch (\Throwable) {
            return false;
        }
    }
}
