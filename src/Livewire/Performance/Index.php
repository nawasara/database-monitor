<?php

namespace Nawasara\DatabaseMonitor\Livewire\Performance;

use Livewire\Attributes\Computed;
use Livewire\Component;
use Nawasara\AuthPrimitives\Attributes\RequiresSudo;
use Nawasara\AuthPrimitives\Traits\WithSudo;
use Nawasara\DatabaseMonitor\Services\MysqlConnection;
use Nawasara\DatabaseMonitor\Services\MysqlInspector;

/**
 * Live performance view — processlist + global status + slow query log.
 *
 * Unlike Dashboard/Index which serves cached snapshots from local tables,
 * this page queries the target MySQL on every render so operators see
 * the actual current state. A wire:poll on the blade keeps it updated
 * every 5 seconds.
 */
class Index extends Component
{
    use WithSudo;

    public bool $hideSleeping = true;

    /** Filter by user (empty = all). */
    public string $userFilter = '';

    /** Filter by DB (empty = all). */
    public string $dbFilter = '';

    /**
     * KILL terminates a running thread — destructive enough (mid-flight
     * transactions roll back, application sessions get a "MySQL server
     * has gone away") to warrant sudo gating in addition to the Spatie
     * permission check. Note: post-2026-05-26 security review elevated
     * this from "P2 high-risk" to "always sudo-gated".
     */
    #[RequiresSudo(reason: 'menghentikan thread MySQL')]
    public function killQuery(int $id): void
    {
        $this->authorize('database-monitor.process.kill');

        try {
            app(MysqlInspector::class)->connection()->statement('KILL ?', [$id]);
            activity('database-monitor')
                ->event('process.kill')
                ->withProperties(['thread_id' => $id])
                ->log("Thread {$id} killed by ".auth()->user()?->email);
            $this->dispatch('toast', [
                'type' => 'success',
                'message' => "Thread {$id} dihentikan.",
            ]);
        } catch (\Throwable $e) {
            report($e);
            activity('database-monitor')
                ->event('process.kill_failed')
                ->withProperties(['thread_id' => $id])
                ->log("Thread {$id} kill FAILED by ".auth()->user()?->email);
            $this->dispatch('toast', [
                'type' => 'error',
                'message' => 'Gagal menghentikan thread (cek log aplikasi).',
            ]);
        } finally {
            app(MysqlConnection::class)->purge();
        }
    }

    #[Computed]
    public function isConfigured(): bool
    {
        return app(MysqlConnection::class)->isConfigured();
    }

    #[Computed]
    public function processes(): array
    {
        if (! $this->isConfigured) {
            return [];
        }

        try {
            $rows = app(MysqlInspector::class)->processList();
        } catch (\Throwable $e) {
            return [];
        } finally {
            app(MysqlConnection::class)->purge();
        }

        return array_values(array_filter($rows, function ($row) {
            if ($this->hideSleeping && $row['command'] === 'Sleep') {
                return false;
            }
            if ($this->userFilter !== '' && stripos($row['user'], $this->userFilter) === false) {
                return false;
            }
            if ($this->dbFilter !== '' && ! str_contains((string) $row['db'], $this->dbFilter)) {
                return false;
            }
            return true;
        }));
    }

    #[Computed]
    public function status(): array
    {
        if (! $this->isConfigured) {
            return [];
        }

        try {
            return app(MysqlInspector::class)->globalStatus();
        } catch (\Throwable $e) {
            return [];
        } finally {
            app(MysqlConnection::class)->purge();
        }
    }

    #[Computed]
    public function slowLog(): array
    {
        if (! $this->isConfigured) {
            return ['enabled' => false, 'queryable' => false, 'log_output' => null, 'long_query_time' => null, 'log_file' => null];
        }

        try {
            return app(MysqlInspector::class)->slowQueryLogState();
        } catch (\Throwable $e) {
            return ['enabled' => false, 'queryable' => false, 'log_output' => null, 'long_query_time' => null, 'log_file' => null];
        } finally {
            app(MysqlConnection::class)->purge();
        }
    }

    #[Computed]
    public function recentSlow(): array
    {
        if (! $this->slowLog['queryable']) {
            return [];
        }

        try {
            return app(MysqlInspector::class)->recentSlowQueries(20);
        } catch (\Throwable $e) {
            return [];
        } finally {
            app(MysqlConnection::class)->purge();
        }
    }

    /**
     * Compute approximate InnoDB buffer pool hit rate from global status.
     * Returns null when the underlying counters aren't available.
     */
    public function bufferPoolHitRate(): ?float
    {
        $reads = (int) ($this->status['Innodb_buffer_pool_reads'] ?? 0);
        $requests = (int) ($this->status['Innodb_buffer_pool_read_requests'] ?? 0);

        if ($requests === 0) {
            return null;
        }

        return round(100 * (1 - $reads / $requests), 2);
    }

    public function render()
    {
        return view('nawasara-database-monitor::livewire.pages.performance.index')
            ->layout('nawasara-ui::components.layouts.app');
    }
}
