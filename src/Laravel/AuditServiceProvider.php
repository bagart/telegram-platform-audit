<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAudit\Laravel;

use BAGArt\TelegramBotAudit\AuditFailurePolicyResolver;
use BAGArt\TelegramBotAudit\AuditQueryContract;
use BAGArt\TelegramBotAudit\AuditSinkContract;
use BAGArt\TelegramBotAudit\CorrelationContext;
use BAGArt\TelegramBotAudit\DefaultAuditFailurePolicyResolver;
use BAGArt\TelegramBotAudit\InMemoryAuditSink;
use BAGArt\TelegramBotAudit\Laravel\Console\Commands\AuditPruneCommand;
use BAGArt\TelegramBotAudit\Laravel\Listeners\RecordAccessControlEvents;
use BAGArt\TelegramBotAudit\Laravel\Listeners\RecordModuleLifecycleEvents;
use BAGArt\TelegramBotAudit\MutableCorrelationContext;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

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

        $this->app->singleton(AuditSinkContract::class, function () {
            $driver = config('audit.driver', 'database');

            return match ($driver) {
                'database' => new DatabaseAuditSink(
                    connection: config('audit.database.connection'),
                    table: config('audit.database.table', 'audit_entries'),
                ),
                'memory' => new InMemoryAuditSink(),
                default => throw new \RuntimeException("Unknown audit driver: {$driver}"),
            };
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

            return new DefaultAuditFailurePolicyResolver(
                operationOverrides: $overrides,
                defaultPolicy: $default,
            );
        });

        // Listeners
        $this->app->singleton(RecordAccessControlEvents::class);
        $this->app->singleton(RecordModuleLifecycleEvents::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../../config/audit.php' => config_path('audit.php'),
        ], 'audit-config');

        $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations');

        $this->loadRoutesFrom(__DIR__ . '/../../routes/web.php');

        if ($this->app->runningInConsole()) {
            $this->commands([AuditPruneCommand::class]);

            // Schedule retention pruner
            $this->app->afterResolving('scheduler', function ($scheduler) {
                $scheduler->command('audit:prune')
                    ->daily()
                    ->at('03:00');
            });
        }
    }
}
