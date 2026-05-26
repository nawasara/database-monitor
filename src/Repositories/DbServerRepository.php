<?php

namespace Nawasara\DatabaseMonitor\Repositories;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Nawasara\DatabaseMonitor\Jobs\SyncDatabaseInventoryJob;
use Nawasara\DatabaseMonitor\Jobs\SyncDatabaseMetricsJob;
use Nawasara\DatabaseMonitor\Models\DbServer;
use Nawasara\Sync\Concerns\TracksLastSync;
use Nawasara\Sync\Models\SyncJob;

/**
 * Thin repository over DbServer / DbDatabase.
 *
 * Deliberately not implementing nawasara/sync's `SyncedRepository` contract
 * — that interface is shaped around CRUD passthrough to external systems
 * (create/update/delete via SyncJob). Database-monitor is read-only against
 * the target server; create/update/delete have no meaningful semantics here.
 */
class DbServerRepository
{
    use TracksLastSync;

    public function all(): Collection
    {
        return DbServer::orderBy('label')->get();
    }

    public function primary(): ?DbServer
    {
        // Single-server deployment per 2026-05-25 — slug constant defined on
        // the model so future multi-server work can grow off the same anchor.
        return DbServer::where('slug', DbServer::SLUG_DEFAULT)->first();
    }

    /**
     * Manual sync from UI — runs inventory first (so a never-synced server
     * gets a row), then metrics. Both jobs dispatch onto the queue; this
     * returns the inventory tracker as the "anchor" for UI progress polling
     * because metrics depends on inventory having succeeded.
     */
    public function syncNow(): ?SyncJob
    {
        SyncDatabaseInventoryJob::dispatch(triggerSource: 'manual');
        SyncDatabaseMetricsJob::dispatch(triggerSource: 'manual');

        return SyncJob::query()
            ->where('service', 'database-monitor')
            ->where('action', 'sync_inventory')
            ->latest('id')
            ->first();
    }

    public function lastSyncedAt(): ?Carbon
    {
        return $this->lastSuccessfulSyncAt('database-monitor', 'sync_inventory');
    }
}
