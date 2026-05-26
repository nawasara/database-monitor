<?php

namespace Nawasara\DatabaseMonitor\Livewire\Dashboard;

use Livewire\Attributes\Computed;
use Livewire\Component;
use Nawasara\DatabaseMonitor\Models\DbDatabase;
use Nawasara\DatabaseMonitor\Models\DbServer;
use Nawasara\DatabaseMonitor\Repositories\DbServerRepository;
use Nawasara\DatabaseMonitor\Services\MysqlConnection;
use Nawasara\DatabaseMonitor\Services\MysqlInspector;

class Index extends Component
{
    /** Show system databases (information_schema, mysql, sys, performance_schema). */
    public bool $showSystem = false;

    /** Database name currently open in the detail modal (null = closed). */
    public ?string $detailName = null;

    /** Top-tables result loaded lazily when the modal opens. */
    public array $detailTopTables = [];

    public function syncNow(DbServerRepository $repository): void
    {
        $repository->syncNow();

        $this->dispatch('toast', [
            'type' => 'info',
            'message' => 'Sinkronisasi dijalankan di latar belakang.',
        ]);
    }

    /**
     * Open the per-database detail panel and fetch the top tables on the
     * fly. Kept out of bulk sync because top-N is O(tables) and we'd
     * waste rows on databases nobody clicks on.
     */
    public function openDetail(string $name): void
    {
        $this->detailName = $name;
        $this->detailTopTables = [];

        $connection = app(MysqlConnection::class);
        if (! $connection->isConfigured()) {
            return;
        }

        try {
            $this->detailTopTables = app(MysqlInspector::class)->topTables($name, 10);
            $this->dispatch('modal-open:database-monitor-top-tables');
        } catch (\Throwable $e) {
            $this->dispatch('toast', [
                'type' => 'error',
                'message' => 'Gagal memuat detail: '.$e->getMessage(),
            ]);
            $this->detailName = null;
        } finally {
            $connection->purge();
        }
    }

    public function closeDetail(): void
    {
        $this->dispatch('modal-close:database-monitor-top-tables');
        $this->detailName = null;
        $this->detailTopTables = [];
    }

    public static function formatBytes(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = (int) floor(log($bytes, 1024));
        $power = min($power, count($units) - 1);

        return number_format($bytes / (1024 ** $power), $power === 0 ? 0 : 2).' '.$units[$power];
    }

    #[Computed]
    public function isConfigured(): bool
    {
        return app(MysqlConnection::class)->isConfigured();
    }

    #[Computed]
    public function server(): ?DbServer
    {
        return app(DbServerRepository::class)->primary();
    }

    #[Computed]
    public function databases()
    {
        $server = $this->server;
        if (! $server) {
            return collect();
        }

        $query = $server->databases();

        if (! $this->showSystem) {
            $query->where('kind', DbDatabase::KIND_USER);
        }

        // Sort by total size descending so the biggest databases land at
        // the top — that's what operators care about first. NULL sizes
        // (never measured) sink to the bottom.
        return $query
            ->orderByRaw('(COALESCE(data_size_bytes, 0) + COALESCE(index_size_bytes, 0)) DESC')
            ->orderBy('name')
            ->get();
    }

    /**
     * Sum of measured sizes across the currently visible database list.
     * Used by the "Total size" stat card.
     */
    #[Computed]
    public function totalSizeBytes(): int
    {
        return (int) $this->databases->sum(fn ($db) => $db->totalSizeBytes());
    }

    public function render()
    {
        return view('nawasara-database-monitor::livewire.pages.dashboard.index')
            ->layout('nawasara-ui::components.layouts.app');
    }
}
