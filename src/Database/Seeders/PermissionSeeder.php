<?php

namespace Nawasara\DatabaseMonitor\Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Permission split rationale:
 *
 *   READ_ONLY    — viewing dashboards, performance counters. Safe to
 *                  auto-grant to the `developer` role at seed time.
 *
 *   WRITE_RISK   — irreversible writes against the target MySQL
 *                  (DROP DATABASE, manage users, kill threads). Even
 *                  with the admin.enabled feature flag, sudo gating,
 *                  and audit log in place, these should never be
 *                  granted by default. Operators with intent must
 *                  assign these explicitly via the user/role UI
 *                  (or a one-off seeder run with --force) so the
 *                  decision shows up in the activity log.
 *
 * The previous (pre-2026-05-26) version of this seeder bulk-granted
 * everything to `developer`. That meant cloning the dev role for any
 * new team member silently handed them DROP DATABASE rights. Security
 * review flagged this as M5; the fix is the split below.
 */
class PermissionSeeder extends Seeder
{
    /** @var list<string> */
    protected array $readOnly = [
        'database-monitor.view',
        'database-monitor.metrics.view',
        'database-monitor.alert.manage',
        'database-monitor.sync.execute',
    ];

    /** @var list<string> */
    protected array $writeRisk = [
        'database-monitor.process.kill',   // KILL terminates running queries
        'database-monitor.database.create', // Fase F
        'database-monitor.database.drop',   // Fase F — irreversible
        'database-monitor.user.manage',     // Fase F — CREATE/DROP/GRANT MySQL users
    ];

    public function run(): void
    {
        foreach (array_merge($this->readOnly, $this->writeRisk) as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $role = Role::where('name', 'developer')->first();

        if ($role) {
            // Safe-by-default — `developer` gets read-only perms automatically.
            $role->givePermissionTo($this->readOnly);

            // Write-risk perms are intentionally NOT auto-granted. To enable
            // them for a role, an operator must explicitly assign via the
            // user-management UI (Spatie) or run a separate seeder. The
            // assignment then surfaces in the activity log, attributable
            // to the operator who made the call.
        }
    }
}
