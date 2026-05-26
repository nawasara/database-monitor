<?php

namespace Nawasara\DatabaseMonitor\Services;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Nawasara\Vault\Facades\Vault;
use PDO;

/**
 * Privileged MySQL connection for Fase F administration (CREATE/DROP
 * database, manage users, GRANT/REVOKE). Strictly separate from
 * {@see MysqlConnection} — which is the read-only path — so:
 *
 *   1. A bug that leaks the admin credential into a query-by-user code
 *      path is much harder to introduce (different class, different
 *      registered connection name).
 *   2. Read sessions stay SET SESSION TRANSACTION READ ONLY. Admin
 *      sessions do not, but they're only reachable from the
 *      `Admin/*` Livewire pages which themselves require
 *      `database-monitor.database.*` permission + sudo.
 *
 * Credentials are stored in the SAME Vault group as the read user
 * (`database-monitor`), but under distinct field names
 * (admin_username / admin_password). Operators see one row in the
 * Vault UI with everything in one place.
 */
class MysqlAdmin
{
    public const CONNECTION_NAME = 'database_monitor_admin';

    /**
     * Three-state config check:
     *   - feature flag enabled
     *   - admin credentials provided in Vault
     * If either is false, the admin pages should refuse to render.
     */
    public function isConfigured(): bool
    {
        if (! (bool) config('nawasara-database-monitor.admin.enabled', false)) {
            return false;
        }

        return ! empty(Vault::get('database-monitor', 'host'))
            && ! empty(Vault::get('database-monitor', 'admin_username'));
    }

    public function isEnabled(): bool
    {
        return (bool) config('nawasara-database-monitor.admin.enabled', false);
    }

    public function connection(): Connection
    {
        config(['database.connections.'.self::CONNECTION_NAME => $this->buildConfig()]);

        return DB::connection(self::CONNECTION_NAME);
    }

    public function purge(): void
    {
        DB::purge(self::CONNECTION_NAME);

        // DB::purge() drops the resolved Connection instance, but the
        // Vault-decrypted admin password still lives in
        // `config('database.connections.database_monitor_admin')`. That's
        // visible to Telescope's "Dumps" tab, Debugbar's config panel,
        // Whoops/Ignition stack-frame inspectors, and tinker. Strip the
        // entry too so plaintext credentials don't linger across the
        // request lifetime.
        $connections = config('database.connections');
        unset($connections[self::CONNECTION_NAME]);
        config(['database.connections' => $connections]);
    }

    /**
     * Probe — used at action time (not bulk) so an offline target surfaces
     * a clear error before we render confirmation modals built on stale data.
     */
    public function testConnection(): array
    {
        if (! $this->isEnabled()) {
            return ['success' => false, 'message' => 'Administration mode dimatikan di config.'];
        }
        if (! $this->isConfigured()) {
            return ['success' => false, 'message' => 'Admin credential belum di-set di Vault.'];
        }

        try {
            $row = $this->connection()->selectOne('SELECT USER() AS user, @@hostname AS host');

            return [
                'success' => true,
                'message' => sprintf('Admin session aktif — %s @ %s', $row->user ?? 'unknown', $row->host ?? 'unknown'),
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Gagal connect: '.$e->getMessage()];
        } finally {
            $this->purge();
        }
    }

    // -----------------------------------------------------------------
    // Database management — Fase F write actions
    // -----------------------------------------------------------------

    /**
     * Create a new schema. Identifier validation is done by callers via
     * {@see sanitiseIdentifier}; the SQL itself uses backtick-quoted
     * identifier so reserved-word names still work.
     */
    public function createDatabase(string $name, string $charset = 'utf8mb4', string $collation = 'utf8mb4_unicode_ci'): void
    {
        $name = $this->sanitiseIdentifier($name);
        $charset = $this->sanitiseCharset($charset);
        $collation = $this->sanitiseCollation($collation);

        $this->connection()->statement(
            "CREATE DATABASE `{$name}` CHARACTER SET {$charset} COLLATE {$collation}"
        );
    }

    /**
     * Drop a schema. The caller is responsible for confirming the action
     * with the operator before invoking — this method does NOT prompt.
     */
    public function dropDatabase(string $name): void
    {
        $name = $this->sanitiseIdentifier($name);
        $this->connection()->statement("DROP DATABASE `{$name}`");
    }

    // -----------------------------------------------------------------
    // User management — Fase F write actions
    // -----------------------------------------------------------------

    /**
     * @return list<array{user:string, host:string}>
     */
    public function listUsers(): array
    {
        $rows = $this->connection()->select('SELECT User AS user, Host AS host FROM mysql.user ORDER BY User, Host');

        return array_map(
            fn ($r) => ['user' => (string) $r->user, 'host' => (string) $r->host],
            $rows,
        );
    }

    /**
     * @return list<string> raw GRANT statements as MySQL reports them
     */
    public function listGrants(string $user, string $host = '%'): array
    {
        $user = $this->sanitiseUsername($user);
        $host = $this->sanitiseHostPattern($host);

        try {
            $rows = $this->connection()->select("SHOW GRANTS FOR `{$user}`@`{$host}`");
        } catch (\Throwable $e) {
            return [];
        }

        // MySQL 5.6 and older emit `IDENTIFIED BY 'hash'` inside SHOW GRANTS
        // output — modern servers don't, but defensively redact in case the
        // monitored target is an older version. The hash is sensitive enough
        // that an operator's clipboard or browser history shouldn't see it.
        return array_map(
            fn ($r) => preg_replace(
                "/IDENTIFIED BY (PASSWORD )?'[^']*'/i",
                "IDENTIFIED BY '***'",
                (string) array_values((array) $r)[0],
            ),
            $rows,
        );
    }

    public function createUser(string $user, string $password, string $host = '%'): void
    {
        $user = $this->sanitiseUsername($user);
        $host = $this->sanitiseHostPattern($host);

        // Password CAN be bound via prepared statement only for `IDENTIFIED BY ?`
        // form on MySQL 8+; on MariaDB / older MySQL the syntax doesn't accept
        // placeholders, so we escape literally. PDO::quote handles edge chars.
        $quoted = $this->connection()->getPdo()->quote($password);

        $this->connection()->statement("CREATE USER `{$user}`@`{$host}` IDENTIFIED BY {$quoted}");
    }

    public function changePassword(string $user, string $newPassword, string $host = '%'): void
    {
        $user = $this->sanitiseUsername($user);
        $host = $this->sanitiseHostPattern($host);
        $quoted = $this->connection()->getPdo()->quote($newPassword);

        // ALTER USER syntax is MySQL 5.7.6+/MariaDB 10.2+. For older the
        // SET PASSWORD form would be needed; targeting modern servers only.
        $this->connection()->statement("ALTER USER `{$user}`@`{$host}` IDENTIFIED BY {$quoted}");
    }

    /**
     * MySQL/MariaDB system accounts that must NEVER be dropped via this UI.
     * Dropping them either breaks the server (mysql.sys → information schema)
     * or breaks our own monitoring (admin user dropping itself).
     */
    protected const PROTECTED_USERS = [
        'root',
        'mysql.sys',
        'mysql.session',
        'mysql.infoschema',
        'mariadb.sys',
        'debian-sys-maint',
        'rdsadmin',
    ];

    public function dropUser(string $user, string $host = '%'): void
    {
        $user = $this->sanitiseUsername($user);
        $host = $this->sanitiseHostPattern($host);

        // Backend safe-list: the UI hides the trash button for these, but
        // an attacker dispatching the Livewire method directly would
        // otherwise bypass that. Service-layer guard = single source of truth.
        if (in_array(strtolower($user), array_map('strtolower', self::PROTECTED_USERS), true)) {
            throw new \InvalidArgumentException("User '{$user}' adalah system account dan tidak boleh dihapus.");
        }

        // Don't let admin drop the user it's authenticated as — that locks
        // monitoring out of the target server permanently.
        $self = (string) \Nawasara\Vault\Facades\Vault::get('database-monitor', 'admin_username');
        if ($self !== '' && strcasecmp($user, $self) === 0) {
            throw new \InvalidArgumentException('Tidak dapat menghapus user admin yang dipakai monitoring sendiri.');
        }

        $this->connection()->statement("DROP USER `{$user}`@`{$host}`");
    }

    /**
     * Grant a coarse privilege set on a single database. Privileges list is
     * VALIDATED against an allow-list — we don't accept arbitrary strings
     * because they go straight into the DDL.
     *
     * @param  list<string>  $privileges  e.g. ['SELECT', 'INSERT', 'UPDATE']
     */
    /**
     * Privileges intentionally NOT in the allow-list:
     *   - ALL PRIVILEGES: a `user.manage`-holder could grant ALL on any
     *     schema and bypass `database.create/.drop` separation.
     *   - SUPER, FILE, GRANT OPTION, RELOAD, SHUTDOWN, PROCESS, CREATE USER,
     *     REPLICATION *: server-wide / privilege-escalation primitives.
     * If a future need calls for these, gate behind a separate permission
     * (e.g. `database-monitor.user.grant-all`) and a distinct audit event.
     */
    protected const GRANT_ALLOW = [
        'SELECT', 'INSERT', 'UPDATE', 'DELETE',
        'CREATE', 'DROP', 'ALTER', 'INDEX',
        'REFERENCES', 'EXECUTE', 'TRIGGER',
    ];

    protected const GRANT_DENY = [
        'ALL', 'ALL PRIVILEGES', 'SUPER', 'FILE', 'PROCESS',
        'GRANT OPTION', 'RELOAD', 'SHUTDOWN', 'CREATE USER',
        'REPLICATION SLAVE', 'REPLICATION CLIENT',
    ];

    public function grant(string $user, string $database, array $privileges, string $host = '%'): void
    {
        $user = $this->sanitiseUsername($user);
        $host = $this->sanitiseHostPattern($host);
        $database = $this->sanitiseIdentifier($database);

        $normalized = array_map(static fn ($p) => strtoupper(trim((string) $p)), $privileges);

        // Hard-deny dangerous tokens before allow-list check so that even
        // if the allow-list is widened later a `SUPER`/`GRANT OPTION` slip
        // is still blocked.
        foreach ($normalized as $p) {
            if (in_array($p, self::GRANT_DENY, true)) {
                throw new \InvalidArgumentException("Privilege '{$p}' dilarang.");
            }
        }

        $filtered = array_values(array_intersect($normalized, self::GRANT_ALLOW));

        if (empty($filtered)) {
            throw new \InvalidArgumentException('Tidak ada privilege valid yang dipilih.');
        }

        $privList = implode(', ', $filtered);
        $this->connection()->statement("GRANT {$privList} ON `{$database}`.* TO `{$user}`@`{$host}`");
        // FLUSH PRIVILEGES is unnecessary for GRANT/REVOKE on MySQL 5.7+
        // — the server auto-reloads. Only needed when modifying mysql.user
        // rows directly via INSERT/UPDATE.
    }

    public function revokeAll(string $user, string $database, string $host = '%'): void
    {
        $user = $this->sanitiseUsername($user);
        $host = $this->sanitiseHostPattern($host);
        $database = $this->sanitiseIdentifier($database);

        $this->connection()->statement("REVOKE ALL PRIVILEGES ON `{$database}`.* FROM `{$user}`@`{$host}`");
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * Schema/database/charset/collation identifier sanitiser. Strict
     * [A-Za-z0-9_]+ — rejects spaces, dots, semicolons, etc. Throws on
     * anything else because MySQL doesn't allow parameter binding for
     * identifiers, so the only safe DDL pattern is "validate strictly,
     * then interpolate with backticks".
     */
    protected function sanitiseIdentifier(string $value): string
    {
        if ($value === '' || ! preg_match('/^[A-Za-z0-9_]+$/', $value)) {
            throw new \InvalidArgumentException("Identifier '{$value}' tidak valid — hanya huruf, angka, underscore.");
        }

        return $value;
    }

    /**
     * Username variant — relaxes the identifier rule to allow `.` because
     * legitimate MySQL system accounts like `mysql.sys` exist. Still no
     * spaces, quotes, semicolons. Used in user-management code paths only.
     */
    protected function sanitiseUsername(string $value): string
    {
        if ($value === '' || ! preg_match('/^[A-Za-z0-9_.]+$/', $value)) {
            throw new \InvalidArgumentException("Username '{$value}' tidak valid.");
        }

        return $value;
    }

    /**
     * Charset names live in MySQL's charset registry; we allow an explicit
     * narrow list rather than free-form to prevent confused-deputy bugs
     * where the operator's input becomes part of `CHARACTER SET ...` DDL.
     */
    protected function sanitiseCharset(string $value): string
    {
        $allow = ['utf8mb4', 'utf8', 'latin1', 'ascii'];
        if (! in_array(strtolower($value), $allow, true)) {
            throw new \InvalidArgumentException("Charset '{$value}' tidak diizinkan.");
        }

        return strtolower($value);
    }

    /**
     * Collation names follow a `<charset>_<...>_ci|cs|bin` shape — anything
     * matching `[a-z0-9_]+` is safe to interpolate. MySQL validates
     * compatibility with the chosen charset at execution time.
     */
    protected function sanitiseCollation(string $value): string
    {
        if (! preg_match('/^[a-z0-9_]+$/', strtolower($value))) {
            throw new \InvalidArgumentException("Collation '{$value}' tidak diizinkan.");
        }

        return strtolower($value);
    }

    /**
     * MySQL hostname patterns allow `%` (any) and `_` (single char) on top
     * of the identifier character set. Tighter than identifier check, still
     * restrictive enough to refuse spaces, quotes, backticks.
     */
    protected function sanitiseHostPattern(string $value): string
    {
        if ($value === '' || ! preg_match('/^[A-Za-z0-9_%.\-]+$/', $value)) {
            throw new \InvalidArgumentException("Host pattern '{$value}' tidak valid.");
        }

        return $value;
    }

    protected function buildConfig(): array
    {
        $timeout = (int) config('nawasara-database-monitor.connection_timeout', 5);

        return [
            'driver' => 'mysql',
            'host' => (string) Vault::get('database-monitor', 'host'),
            'port' => (int) (Vault::get('database-monitor', 'port') ?: 3306),
            'database' => '',
            'username' => (string) Vault::get('database-monitor', 'admin_username'),
            'password' => (string) Vault::get('database-monitor', 'admin_password'),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => false,
            'engine' => null,
            'options' => array_filter([
                PDO::ATTR_TIMEOUT => $timeout,
                // NO READ-ONLY init command — admin needs write.
            ]),
        ];
    }
}
