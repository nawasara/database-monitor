<?php

namespace Nawasara\DatabaseMonitor\Services;

use Nawasara\Alerting\Facades\Alerter;
use Nawasara\DatabaseMonitor\Models\DbServer;

/**
 * Evaluate alert rules for a single DbServer. Dispatch goes through the
 * nawasara/alerting primitive — this class is now just the domain-specific
 * predicate layer (does the threshold breach hold right now?).
 *
 * Rule keys live in code (registered in DatabaseMonitorServiceProvider):
 *   db.server.unreachable
 *   db.server.connections_high
 *   db.server.aborted_connects_high
 *
 * The alerting package handles state transitions, cooldown, re-notify,
 * acknowledgement, silencing — none of that is our concern anymore.
 *
 * Sprint 1.5 refactor: dropped DbAlertState write path + ad-hoc
 * notification dispatch. DbAlertState model rows remain for now so a
 * rollback to v0.1 doesn't lose history; v0.3 will drop the table.
 */
class AlertEvaluator
{
    public const RULE_SERVER_UNREACHABLE = 'db.server.unreachable';
    public const RULE_CONNECTIONS_HIGH = 'db.server.connections_high';
    public const RULE_ABORTED_HIGH = 'db.server.aborted_connects_high';

    public function evaluate(DbServer $server): array
    {
        if (! (bool) config('nawasara-database-monitor.alerts.enabled', true)) {
            return ['skipped' => 'alerts_disabled'];
        }

        return [
            'server.unreachable' => $this->evaluateUnreachable($server),
            'connections.high' => $this->evaluateConnectionsHigh($server),
            'aborted.high' => $this->evaluateAbortedHigh($server),
        ];
    }

    protected function evaluateUnreachable(DbServer $server): array
    {
        $firing = $server->status !== DbServer::STATUS_ONLINE;
        $context = [
            'server' => $server->slug,
            'label' => $server->label,
            'status' => $server->status,
            'status_message' => $server->status_message,
        ];

        return $this->dispatchTransition(self::RULE_SERVER_UNREACHABLE, $server, $firing, $context);
    }

    protected function evaluateConnectionsHigh(DbServer $server): array
    {
        $threshold = (int) config('nawasara-database-monitor.alerts.connections_pct', 80);
        $maxConn = (int) $server->max_connections;

        if ($maxConn <= 0) {
            return ['state' => 'unknown', 'reason' => 'max_connections unknown'];
        }

        try {
            $status = app(MysqlInspector::class)->globalStatus();
        } catch (\Throwable $e) {
            return ['state' => 'unknown', 'reason' => 'probe_failed: '.$e->getMessage()];
        } finally {
            app(MysqlConnection::class)->purge();
        }

        $connected = (int) ($status['Threads_connected'] ?? 0);
        $pct = (int) round(100 * $connected / $maxConn);
        $firing = $pct >= $threshold;

        $context = [
            'server' => $server->slug,
            'label' => $server->label,
            'threads_connected' => $connected,
            'max_connections' => $maxConn,
            'pct' => $pct,
            'threshold_pct' => $threshold,
        ];

        return $this->dispatchTransition(self::RULE_CONNECTIONS_HIGH, $server, $firing, $context);
    }

    protected function evaluateAbortedHigh(DbServer $server): array
    {
        $threshold = (int) config('nawasara-database-monitor.alerts.aborted_connects_per_min', 10);

        try {
            $status = app(MysqlInspector::class)->globalStatus();
        } catch (\Throwable $e) {
            return ['state' => 'unknown', 'reason' => 'probe_failed: '.$e->getMessage()];
        } finally {
            app(MysqlConnection::class)->purge();
        }

        $current = (int) ($status['Aborted_connects'] ?? 0);
        $previous = (int) ($server->getAttribute('last_aborted_connects') ?? 0);

        // Persist current counter for next tick's delta computation.
        $server->forceFill(['last_aborted_connects' => $current])->saveQuietly();

        if ($previous === 0) {
            return ['state' => 'baseline_set', 'value' => $current];
        }

        $delta = max(0, $current - $previous);
        $firing = $delta >= $threshold;

        $context = [
            'server' => $server->slug,
            'label' => $server->label,
            'delta' => $delta,
            'previous' => $previous,
            'current' => $current,
            'threshold' => $threshold,
        ];

        return $this->dispatchTransition(self::RULE_ABORTED_HIGH, $server, $firing, $context);
    }

    /**
     * Bridge predicate result → Alerter facade. The alerting package owns
     * state transitions, cooldown, re-notify — we just fire-or-resolve
     * idempotently per evaluation tick.
     */
    protected function dispatchTransition(
        string $ruleKey,
        DbServer $server,
        bool $firing,
        array $context,
    ): array {
        if ($firing) {
            Alerter::fire($ruleKey, 'DbServer', (string) $server->id, $context);

            return ['state' => 'firing', 'rule' => $ruleKey];
        }

        Alerter::resolve($ruleKey, 'DbServer', (string) $server->id);

        return ['state' => 'ok', 'rule' => $ruleKey];
    }
}
