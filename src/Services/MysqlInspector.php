<?php

namespace Nawasara\DatabaseMonitor\Services;

/**
 * Read-only queries against the monitored MySQL server.
 *
 * All methods operate via MysqlConnection — never touches the app's own
 * database. Method bodies are deliberately small; complex aggregations
 * (uptime %, growth trend) live in dedicated services so this class stays
 * a thin SQL wrapper.
 */
class MysqlInspector
{
    public function __construct(protected MysqlConnection $connection) {}

    /**
     * Underlying DB connection. Exposed so callers needing raw exec
     * (e.g. KILL <thread-id>) don't need to round-trip via MysqlConnection.
     */
    public function connection(): \Illuminate\Database\ConnectionInterface
    {
        return $this->connection->connection();
    }

    public function serverInfo(): array
    {
        $conn = $this->connection->connection();
        $row = $conn->selectOne(<<<'SQL'
            SELECT
                VERSION() AS version,
                @@hostname AS hostname,
                @@version_compile_os AS os,
                @@datadir AS datadir,
                (SELECT VARIABLE_VALUE FROM performance_schema.global_status WHERE VARIABLE_NAME = 'Uptime') AS uptime_seconds,
                @@max_connections AS max_connections
        SQL);

        return [
            'version' => $row->version ?? null,
            'hostname' => $row->hostname ?? null,
            'os' => $row->os ?? null,
            'datadir' => $row->datadir ?? null,
            'uptime_seconds' => (int) ($row->uptime_seconds ?? 0),
            'max_connections' => (int) ($row->max_connections ?? 0),
        ];
    }

    /**
     * List user databases. System schemas are NOT filtered here — that
     * filter is applied at the UI layer per
     * config('nawasara-database-monitor.system_databases') so power-users
     * can opt-in to see them.
     *
     * @return list<string>
     */
    public function databases(): array
    {
        $rows = $this->connection->connection()->select('SHOW DATABASES');

        return array_map(
            fn ($row) => $row->Database ?? array_values((array) $row)[0],
            $rows,
        );
    }

    /**
     * Cheap reachability probe — returns latency ms on success, throws on
     * any connection or auth failure. Callers (SyncJob) wrap this to record
     * a structured failure on the DbServer row.
     */
    public function ping(): float
    {
        $start = microtime(true);
        $this->connection->connection()->selectOne('SELECT 1');

        return round((microtime(true) - $start) * 1000, 2);
    }

    /**
     * Classify a database name against the configured system-schema list.
     */
    public function isSystemDatabase(string $name): bool
    {
        $system = (array) config('nawasara-database-monitor.system_databases', []);

        return in_array($name, $system, true);
    }

    /**
     * Aggregate data + index size per schema in one round-trip. Output is
     * indexed by schema name so callers can iterate the in-memory list of
     * databases (from `databases()`) and pull metrics in O(1).
     *
     * `TABLE_ROWS` from information_schema is an InnoDB *estimate* —
     * accurate enough for dashboard signals, much cheaper than `COUNT(*)`
     * which would lock-scan tables on a busy server.
     *
     * @return array<string, array{data:int, index:int, tables:int, rows:int}>
     */
    public function databaseSizes(): array
    {
        $rows = $this->connection->connection()->select(<<<'SQL'
            SELECT
                TABLE_SCHEMA AS schema_name,
                COALESCE(SUM(DATA_LENGTH), 0)  AS data_bytes,
                COALESCE(SUM(INDEX_LENGTH), 0) AS index_bytes,
                COUNT(*) AS table_count,
                COALESCE(SUM(TABLE_ROWS), 0) AS row_estimate
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA NOT IN ('information_schema', 'performance_schema')
            GROUP BY TABLE_SCHEMA
        SQL);

        $out = [];
        foreach ($rows as $row) {
            $out[$row->schema_name] = [
                'data' => (int) $row->data_bytes,
                'index' => (int) $row->index_bytes,
                'tables' => (int) $row->table_count,
                'rows' => (int) $row->row_estimate,
            ];
        }

        return $out;
    }

    /**
     * Current MySQL processlist — running threads with their query text.
     * `PROCESS` privilege required to see other users' threads; without it
     * MySQL silently returns only the connecting user's own threads.
     *
     * Query text is truncated to 200 chars at the DB layer — protects
     * against PII leak into the dashboard (full query may contain WHERE
     * clauses with sensitive values).
     *
     * @return list<array{id:int, user:string, host:?string, db:?string, command:string, time:int, state:?string, info:?string}>
     */
    public function processList(): array
    {
        $rows = $this->connection->connection()->select(<<<'SQL'
            SELECT
                ID            AS id,
                USER          AS user,
                HOST          AS host,
                DB            AS db,
                COMMAND       AS command,
                TIME          AS time,
                STATE         AS state,
                LEFT(INFO, 200) AS info
            FROM information_schema.PROCESSLIST
            ORDER BY TIME DESC
        SQL);

        return array_map(
            fn ($row) => [
                'id' => (int) $row->id,
                'user' => (string) $row->user,
                'host' => $row->host !== null ? (string) $row->host : null,
                'db' => $row->db !== null ? (string) $row->db : null,
                'command' => (string) $row->command,
                'time' => (int) $row->time,
                'state' => $row->state !== null ? (string) $row->state : null,
                'info' => $row->info !== null ? (string) $row->info : null,
            ],
            $rows,
        );
    }

    /**
     * Pull a curated set of global status counters. MySQL `SHOW GLOBAL
     * STATUS` returns ~400 keys, most of which are noise for a dashboard.
     * Whitelist below picks the signals operators actually watch.
     *
     * @return array<string, string|int>
     */
    public function globalStatus(): array
    {
        $rows = $this->connection->connection()->select(<<<'SQL'
            SELECT VARIABLE_NAME AS name, VARIABLE_VALUE AS value
            FROM performance_schema.global_status
            WHERE VARIABLE_NAME IN (
                'Threads_connected',
                'Threads_running',
                'Queries',
                'Questions',
                'Slow_queries',
                'Aborted_connects',
                'Aborted_clients',
                'Uptime',
                'Innodb_buffer_pool_reads',
                'Innodb_buffer_pool_read_requests',
                'Connections',
                'Max_used_connections'
            )
        SQL);

        $out = [];
        foreach ($rows as $row) {
            $out[$row->name] = $row->value;
        }

        return $out;
    }

    /**
     * Is slow query log enabled, and where does it go?
     *
     * Returns `null` for any field MySQL doesn't expose (older versions or
     * restricted user). UI uses this to swap between "you have N slow
     * queries" view and "enable slow_query_log to see this data" banner.
     */
    public function slowQueryLogState(): array
    {
        $rows = $this->connection->connection()->select(<<<'SQL'
            SELECT VARIABLE_NAME AS name, VARIABLE_VALUE AS value
            FROM performance_schema.global_variables
            WHERE VARIABLE_NAME IN ('slow_query_log', 'slow_query_log_file', 'log_output', 'long_query_time')
        SQL);

        $map = [];
        foreach ($rows as $row) {
            $map[$row->name] = $row->value;
        }

        return [
            'enabled' => ($map['slow_query_log'] ?? 'OFF') === 'ON',
            'log_output' => $map['log_output'] ?? null,
            'long_query_time' => isset($map['long_query_time']) ? (float) $map['long_query_time'] : null,
            'log_file' => $map['slow_query_log_file'] ?? null,
            // 'TABLE' output means slow log written to mysql.slow_log — readable via SELECT
            'queryable' => str_contains(strtoupper($map['log_output'] ?? ''), 'TABLE'),
        ];
    }

    /**
     * Read recent slow query entries from `mysql.slow_log`. Only callable
     * if log_output contains TABLE — otherwise the table is empty even
     * with slow_query_log = ON.
     *
     * @return list<array{started:string, user:string, query_time:float, lock_time:float, rows_examined:int, db:?string, sql:string}>
     */
    public function recentSlowQueries(int $limit = 20): array
    {
        $rows = $this->connection->connection()->select(
            <<<'SQL'
            SELECT
                start_time             AS started,
                user_host              AS user,
                TIME_TO_SEC(query_time) + (MICROSECOND(query_time) / 1000000) AS query_time,
                TIME_TO_SEC(lock_time) + (MICROSECOND(lock_time) / 1000000)   AS lock_time,
                rows_examined,
                db,
                LEFT(CONVERT(sql_text USING utf8), 500) AS sql_text
            FROM mysql.slow_log
            ORDER BY start_time DESC
            LIMIT ?
            SQL,
            [$limit]
        );

        return array_map(
            fn ($row) => [
                'started' => (string) $row->started,
                'user' => (string) $row->user,
                'query_time' => (float) $row->query_time,
                'lock_time' => (float) $row->lock_time,
                'rows_examined' => (int) $row->rows_examined,
                'db' => $row->db !== null && $row->db !== '' ? (string) $row->db : null,
                'sql' => (string) $row->sql_text,
            ],
            $rows,
        );
    }

    /**
     * Top-N largest tables in a single database. Used by the per-database
     * detail modal — not pulled in bulk because it would explode on
     * servers with 10k+ tables.
     *
     * @return list<array{table:string, data:int, index:int, rows:int}>
     */
    public function topTables(string $schema, int $limit = 10): array
    {
        $rows = $this->connection->connection()->select(
            <<<'SQL'
            SELECT
                TABLE_NAME AS table_name,
                COALESCE(DATA_LENGTH, 0)  AS data_bytes,
                COALESCE(INDEX_LENGTH, 0) AS index_bytes,
                COALESCE(TABLE_ROWS, 0)   AS row_estimate
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = ?
              AND TABLE_TYPE = 'BASE TABLE'
            ORDER BY (COALESCE(DATA_LENGTH, 0) + COALESCE(INDEX_LENGTH, 0)) DESC
            LIMIT ?
            SQL,
            [$schema, $limit]
        );

        return array_map(
            fn ($row) => [
                'table' => $row->table_name,
                'data' => (int) $row->data_bytes,
                'index' => (int) $row->index_bytes,
                'rows' => (int) $row->row_estimate,
            ],
            $rows,
        );
    }
}
