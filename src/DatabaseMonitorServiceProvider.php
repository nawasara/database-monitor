<?php

namespace Nawasara\DatabaseMonitor;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Nawasara\DatabaseMonitor\Jobs\CheckDatabaseAlertsJob;
use Nawasara\DatabaseMonitor\Jobs\SyncDatabaseInventoryJob;
use Nawasara\DatabaseMonitor\Jobs\SyncDatabaseMetricsJob;
use Nawasara\DatabaseMonitor\Services\MysqlConnection;
use Nawasara\DatabaseMonitor\Services\MysqlInspector;
use Symfony\Component\Finder\Finder;

class DatabaseMonitorServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'nawasara-database-monitor');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if (is_dir(__DIR__.'/../resources/views/components')) {
            Blade::anonymousComponentPath(__DIR__.'/../resources/views/components', 'nawasara-database-monitor');
        }

        $this->registerLivewire();
        $this->registerSchedule();
    }

    /**
     * Schedule SyncDatabaseInventoryJob via $schedule->call() per the
     * project rule (see reference_schedule_call_workaround): registering a
     * console command from a package doesn't always surface in the Artisan
     * kernel, so $schedule->command('foo:bar') can fail. Calling dispatch
     * directly from the scheduler closure avoids that.
     */
    protected function registerSchedule(): void
    {
        $this->app->booted(function () {
            if (! $this->app->runningInConsole()) {
                return;
            }

            if (! config('nawasara-database-monitor.scheduler.enabled', true)) {
                return;
            }

            $inventoryInterval = max(1, (int) config('nawasara-database-monitor.sync_interval', 15));
            $metricsInterval = max(1, (int) config('nawasara-database-monitor.metrics_interval', 5));

            $schedule = $this->app->make(Schedule::class);

            $schedule->call(function () {
                SyncDatabaseInventoryJob::dispatch(triggerSource: 'scheduled');
            })
                ->name('nawasara-database-monitor:sync-inventory')
                ->cron("*/{$inventoryInterval} * * * *")
                ->withoutOverlapping(10);

            $schedule->call(function () {
                SyncDatabaseMetricsJob::dispatch(triggerSource: 'scheduled');
            })
                ->name('nawasara-database-monitor:sync-metrics')
                ->cron("*/{$metricsInterval} * * * *")
                ->withoutOverlapping(10);

            // Alerts evaluator — cheap (mostly local cache reads), short
            // cadence so unreachable events are caught within a minute.
            $alertsInterval = max(1, (int) config('nawasara-database-monitor.alerts_interval', 1));

            $schedule->call(function () {
                CheckDatabaseAlertsJob::dispatch(triggerSource: 'scheduled');
            })
                ->name('nawasara-database-monitor:check-alerts')
                ->cron("*/{$alertsInterval} * * * *")
                ->withoutOverlapping(5);
        });
    }

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/nawasara-database-monitor.php', 'nawasara-database-monitor');

        $this->app->singleton(MysqlConnection::class, fn () => new MysqlConnection());
        $this->app->singleton(MysqlInspector::class, fn ($app) => new MysqlInspector(
            $app->make(MysqlConnection::class)
        ));
    }

    protected function registerLivewire(): void
    {
        $namespace = 'Nawasara\\DatabaseMonitor\\Livewire';
        $basePath = __DIR__.'/Livewire';

        if (! is_dir($basePath)) {
            return;
        }

        $finder = new Finder();
        $finder->files()->in($basePath)->name('*.php');

        foreach ($finder as $file) {
            $relativePath = str_replace('/', '\\', $file->getRelativePathname());
            $class = $namespace.'\\'.Str::beforeLast($relativePath, '.php');

            if (class_exists($class)) {
                $alias = 'nawasara-database-monitor.'.
                    Str::of($relativePath)
                        ->replace('.php', '')
                        ->replace('\\', '.')
                        ->replace('/', '.')
                        ->explode('.')
                        ->map(fn ($segment) => Str::kebab($segment))
                        ->join('.');

                Livewire::component($alias, $class);
            }
        }
    }
}
