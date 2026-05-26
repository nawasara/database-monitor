# nawasara/database-monitor

MySQL/MariaDB inventory, capacity, performance, and alert monitoring for the
[Nawasara](https://github.com/nawasara) superapp framework.

Scoped to **one central MySQL server** (the Kominfo deployment baseline as of
2026-05-25) but the schema stays multi-server capable.

## Phases

| Phase | Scope | Status |
|---|---|---|
| A | Inventory + uptime | scaffolding |
| B | Capacity (DB size, top tables, growth) | planned |
| C | Performance (processlist, slow query auto-detect, global status) | planned |
| ~~D~~ | ~~Backup + replication~~ | DROPPED — no replication, no standard backup tool yet |
| E | Alerts via `nawasara/notification` | planned |
| F | Administration (CREATE/DROP DB, manage MySQL users) | planned, separate PR |

See [`docs/todo-database-monitor.md`](../../docs/todo-database-monitor.md) for
the full plan.

## Setup

### 1. Create the monitoring user on the target MySQL server

```sql
CREATE USER 'nawasara_monitor'@'%' IDENTIFIED BY '<strong-password>';

GRANT SELECT, PROCESS, REPLICATION CLIENT, SHOW DATABASES, SHOW VIEW
    ON *.* TO 'nawasara_monitor'@'%';

FLUSH PRIVILEGES;
```

Restrict the host to your Nawasara container IP when possible:

```sql
CREATE USER 'nawasara_monitor'@'10.x.x.x' IDENTIFIED BY '<strong-password>';
```

Verify:

```sql
SHOW GRANTS FOR 'nawasara_monitor'@'%';
-- Must be exactly:
-- GRANT SELECT, PROCESS, REPLICATION CLIENT, SHOW DATABASES, SHOW VIEW ON *.* TO `nawasara_monitor`@`%`
```

The user is **read-only by design** — no `INSERT`, `UPDATE`, `DELETE`,
`CREATE`, `DROP`, or `GRANT` privileges. Phase F administration uses a
separate, higher-privilege user (created when that phase ships).

### 2. Store credentials in Vault

Open **Nawasara → Pengaturan → Vault → Database Monitor** and fill:

- **Host** — hostname or IP of the MySQL server
- **Port** — usually `3306`
- **Database** — leave blank (we connect server-wide, not per-DB)
- **Username** — `nawasara_monitor`
- **Password** — the strong password from step 1

Click **Test Connection** to verify.

### 3. Seed permissions

```bash
php artisan db:seed --class="Nawasara\\DatabaseMonitor\\Database\\Seeders\\PermissionSeeder"
```

This creates the `database-monitor.*` permissions and grants them to the
`developer` role (if present).

## Privileges explained

| Grant | Purpose |
|---|---|
| `SELECT ON *.*` | Reads `information_schema` (sizes, table list), `performance_schema` (status, processlist), and `mysql.slow_log` (slow query, when enabled) |
| `PROCESS` | Required for `SHOW PROCESSLIST` to see all sessions, not just the monitor user's own |
| `REPLICATION CLIENT` | `SHOW REPLICA STATUS` — granted defensively so replication monitoring works the day replication is enabled, without re-granting |
| `SHOW DATABASES` | Lists all schemas regardless of user permissions |
| `SHOW VIEW` | Reads view definitions for sizing |

## Runtime connection — no `.env` leakage

The package never registers `DATABASE_MONITOR_*` env vars or pushes anything
into `config/database.php`. Connection config is built at runtime from Vault
each time a job or page needs it, used, then **purged** to release the LAN
socket. Defence-in-depth: every session runs with
`SET SESSION TRANSACTION READ ONLY` regardless of the granted privileges.

## License

MIT.
