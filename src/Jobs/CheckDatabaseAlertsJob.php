<?php

namespace Nawasara\DatabaseMonitor\Jobs;

use Nawasara\DatabaseMonitor\Models\DbServer;
use Nawasara\DatabaseMonitor\Services\AlertEvaluator;
use Nawasara\Sync\Jobs\AbstractSyncJob;

/**
 * Fase E — evaluate alert rules every alerts_interval minutes. Reads
 * mostly from local cache (last sync) so it's cheap even with multiple
 * rules; one probe-style rule (connections.high) hits the target server
 * once per tick.
 *
 * Persisted via AbstractSyncJob so the Sync Jobs UI shows alert
 * evaluation history with the same UX as inventory/metrics.
 */
class CheckDatabaseAlertsJob extends AbstractSyncJob
{
    public int $timeout = 30;

    protected function service(): string
    {
        return 'database-monitor';
    }

    protected function action(): string
    {
        return 'check_alerts';
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
        $server = DbServer::where('slug', DbServer::SLUG_DEFAULT)->first();

        if (! $server) {
            return ['skipped' => 'no_server_row'];
        }

        return app(AlertEvaluator::class)->evaluate($server);
    }
}
