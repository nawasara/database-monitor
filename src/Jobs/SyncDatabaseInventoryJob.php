<?php

namespace Nawasara\DatabaseMonitor\Jobs;

use Nawasara\DatabaseMonitor\Models\DbDatabase;
use Nawasara\DatabaseMonitor\Models\DbServer;
use Nawasara\DatabaseMonitor\Services\MysqlConnection;
use Nawasara\DatabaseMonitor\Services\MysqlInspector;
use Nawasara\Sync\Jobs\AbstractSyncJob;

/**
 * Fase A — pull server info + database list from the monitored MySQL server,
 * upsert into `nawasara_db_servers` and `nawasara_db_databases`.
 *
 * Single-server deployment (1 row in db_servers, slug `kominfo-central`) per
 * the 2026-05-25 decision. The job is shaped so adding a second server later
 * is just "loop over Vault group instances" — no schema change required.
 */
class SyncDatabaseInventoryJob extends AbstractSyncJob
{
    public int $timeout = 60;

    protected function service(): string
    {
        return 'database-monitor';
    }

    protected function action(): string
    {
        return 'sync_inventory';
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

        $server = DbServer::firstOrNew(['slug' => DbServer::SLUG_DEFAULT]);
        // Use Vault label if a future field is added; for now keep the
        // human-readable default. Don't overwrite on subsequent runs in case
        // an admin renames the row through the UI later.
        if (! $server->exists) {
            $server->label = 'Kominfo Central MySQL';
        }

        try {
            $inspector = app(MysqlInspector::class);
            $info = $inspector->serverInfo();
            $databases = $inspector->databases();
        } catch (\Throwable $e) {
            $server->fill([
                'status' => DbServer::STATUS_UNREACHABLE,
                'status_message' => $this->shortMessage($e->getMessage()),
                'last_synced_at' => now(),
            ])->save();

            throw $e;
        } finally {
            $connection->purge();
        }

        $server->fill([
            'version' => $info['version'],
            'hostname' => $info['hostname'],
            'os' => $info['os'],
            'datadir' => $info['datadir'],
            'uptime_seconds' => $info['uptime_seconds'],
            'max_connections' => $info['max_connections'],
            'database_count' => count($databases),
            'status' => DbServer::STATUS_ONLINE,
            'status_message' => null,
            'last_synced_at' => now(),
        ])->save();

        $stats = $this->upsertDatabases($server, $databases, app(MysqlInspector::class));

        return [
            'server_slug' => $server->slug,
            'databases_total' => count($databases),
            'databases_created' => $stats['created'],
            'databases_unchanged' => $stats['unchanged'],
            'databases_removed' => $stats['removed'],
        ];
    }

    /**
     * @param  list<string>  $databases
     * @return array{created:int, unchanged:int, removed:int}
     */
    protected function upsertDatabases(DbServer $server, array $databases, MysqlInspector $inspector): array
    {
        $stats = ['created' => 0, 'unchanged' => 0, 'removed' => 0];

        $existing = $server->databases()->pluck('name')->all();
        $seen = [];

        foreach ($databases as $name) {
            $seen[] = $name;
            $kind = $inspector->isSystemDatabase($name) ? DbDatabase::KIND_SYSTEM : DbDatabase::KIND_USER;

            $row = DbDatabase::firstOrNew([
                'server_id' => $server->id,
                'name' => $name,
            ]);

            if ($row->exists && $row->kind === $kind) {
                $row->last_synced_at = now();
                $row->save();
                $stats['unchanged']++;
                continue;
            }

            $row->fill([
                'kind' => $kind,
                'last_synced_at' => now(),
            ])->save();

            if (! in_array($name, $existing, true)) {
                $stats['created']++;
            }
        }

        // Drop databases that no longer exist on the target. Size snapshots
        // are intentionally kept here — Fase B can introduce a separate
        // soft-delete strategy if historical analysis becomes important.
        $removed = $server->databases()->whereNotIn('name', $seen)->delete();
        $stats['removed'] = $removed;

        return $stats;
    }

    protected function shortMessage(string $message): string
    {
        return \Illuminate\Support\Str::limit($message, 240);
    }
}
