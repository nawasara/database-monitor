<?php

namespace Nawasara\DatabaseMonitor\Jobs;

use Nawasara\DatabaseMonitor\Models\DbDatabase;
use Nawasara\DatabaseMonitor\Models\DbServer;
use Nawasara\DatabaseMonitor\Models\DbSizeSnapshot;
use Nawasara\DatabaseMonitor\Services\MysqlConnection;
use Nawasara\DatabaseMonitor\Services\MysqlInspector;
use Nawasara\Sync\Jobs\AbstractSyncJob;

/**
 * Fase B — pull data/index size + table count + row estimate per database,
 * update the latest snapshot on `nawasara_db_databases`, and append a row
 * to `nawasara_db_size_snapshots` for historical trending.
 *
 * Runs more frequently than inventory because sizes are what dashboards
 * actually surface; the inventory job (`SyncDatabaseInventoryJob`) only
 * cares about schema existence which barely changes.
 */
class SyncDatabaseMetricsJob extends AbstractSyncJob
{
    public int $timeout = 90;

    protected function service(): string
    {
        return 'database-monitor';
    }

    protected function action(): string
    {
        return 'sync_metrics';
    }

    protected function targetType(): ?string
    {
        return 'DbServer';
    }

    protected function targetId(): ?string
    {
        return DbServer::SLUG_DEFAULT;
    }

    protected function execute(): array
    {
        $connection = app(MysqlConnection::class);

        if (! $connection->isConfigured()) {
            throw new \RuntimeException('Database-monitor Vault credentials are not configured.');
        }

        $server = DbServer::where('slug', DbServer::SLUG_DEFAULT)->first();

        if (! $server) {
            throw new \RuntimeException('Run sync_inventory first — no server row yet.');
        }

        try {
            $sizes = app(MysqlInspector::class)->databaseSizes();
        } finally {
            $connection->purge();
        }

        $stats = [
            'databases_measured' => 0,
            'snapshots_written' => 0,
            'skipped_no_inventory' => 0,
        ];

        $now = now();

        foreach ($sizes as $name => $metrics) {
            $database = $server->databases()->where('name', $name)->first();

            // Inventory job hasn't seen this DB yet — skip silently. Next
            // inventory tick will pick it up, the tick after that will get
            // its metrics. No race condition worth solving.
            if (! $database) {
                $stats['skipped_no_inventory']++;
                continue;
            }

            $database->fill([
                'data_size_bytes' => $metrics['data'],
                'index_size_bytes' => $metrics['index'],
                'table_count' => $metrics['tables'],
                'row_estimate' => $metrics['rows'],
                'last_synced_at' => $now,
            ])->save();
            $stats['databases_measured']++;

            DbSizeSnapshot::create([
                'database_id' => $database->id,
                'data_size_bytes' => $metrics['data'],
                'index_size_bytes' => $metrics['index'],
                'table_count' => $metrics['tables'],
                'row_estimate' => $metrics['rows'],
                'captured_at' => $now,
            ]);
            $stats['snapshots_written']++;
        }

        return $stats;
    }
}
