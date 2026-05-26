<?php

namespace Nawasara\DatabaseMonitor\Services;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Nawasara\Vault\Facades\Vault;
use PDO;

/**
 * Manage runtime MySQL connection to the monitored Kominfo central DB server.
 *
 * Designed deliberately so we never touch `config/database.php` (which would
 * leak credentials into the app's default connection set). Instead the
 * connection is registered into the container at runtime, used, then purged.
 *
 * Single-server scope per 2026-05-25 decision — schema in `db_servers`
 * remains multi-row capable so a future second server can be added without
 * migration changes.
 */
class MysqlConnection
{
    public const CONNECTION_NAME = 'database_monitor_target';

    public function isConfigured(): bool
    {
        // Password intentionally not required — local MySQL setups (Laragon,
        // XAMPP) commonly run root with no password. The connection attempt
        // itself will fail visibly if credentials are wrong; testConnection()
        // surfaces that to the operator.
        return ! empty(Vault::get('database-monitor', 'host'))
            && ! empty(Vault::get('database-monitor', 'username'));
    }

    /**
     * Register connection config (idempotent — re-registering with same key
     * just overwrites) and return the connection instance.
     */
    public function connection(): Connection
    {
        config(['database.connections.'.self::CONNECTION_NAME => $this->buildConfig()]);

        return DB::connection(self::CONNECTION_NAME);
    }

    /**
     * Force-drop the cached connection — call after long-running jobs to
     * release LAN sockets, especially when iterating over many servers (when
     * we extend to multi-server later).
     */
    public function purge(): void
    {
        DB::purge(self::CONNECTION_NAME);

        // Also strip the connection entry from the config repository — the
        // password lives there in plaintext after the first call(),
        // surfaceable via Telescope/Debugbar/tinker. See [[security_admin_purge]].
        $connections = config('database.connections');
        unset($connections[self::CONNECTION_NAME]);
        config(['database.connections' => $connections]);
    }

    /**
     * Quick reachability + auth probe — used by the Vault group's test
     * callback (config('nawasara-vault.groups.database-monitor.test')).
     */
    public function testConnection(): array
    {
        if (! $this->isConfigured()) {
            return [
                'success' => false,
                'message' => 'Kredensial belum di-set di Vault.',
            ];
        }

        try {
            $row = $this->connection()->selectOne('SELECT VERSION() AS version, @@hostname AS hostname');

            return [
                'success' => true,
                'message' => sprintf('Connected — %s @ %s', $row->version ?? 'unknown', $row->hostname ?? 'unknown'),
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => 'Gagal connect: '.$e->getMessage(),
            ];
        } finally {
            $this->purge();
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildConfig(): array
    {
        $timeout = (int) config('nawasara-database-monitor.connection_timeout', 5);

        return [
            'driver' => 'mysql',
            'host' => (string) Vault::get('database-monitor', 'host'),
            'port' => (int) (Vault::get('database-monitor', 'port') ?: 3306),
            'database' => (string) (Vault::get('database-monitor', 'database') ?: ''),
            'username' => (string) Vault::get('database-monitor', 'username'),
            'password' => (string) Vault::get('database-monitor', 'password'),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => false,
            'engine' => null,
            'options' => array_filter([
                PDO::ATTR_TIMEOUT => $timeout,
                // Defence-in-depth — even if the granted user accidentally has
                // write privilege, every query in this session is forced read-only.
                PDO::MYSQL_ATTR_INIT_COMMAND => 'SET SESSION TRANSACTION READ ONLY',
            ]),
        ];
    }
}
