<?php

namespace Nawasara\DatabaseMonitor\Services;

use Illuminate\Support\Facades\Log;
use Nawasara\DatabaseMonitor\Models\DbAlertState;
use Nawasara\DatabaseMonitor\Models\DbServer;
use Nawasara\Notification\Facades\Notify;

/**
 * Evaluate alert rules for a single DbServer.
 *
 * Lifecycle per rule (per call to evaluate()):
 *   1. Run the predicate against the server's current cached state +
 *      (where needed) a fresh probe via MysqlInspector.
 *   2. Compare to previously persisted DbAlertState.
 *   3. Three transitions trigger a notification:
 *      - ok → firing            (alert "started")
 *      - firing → ok            (alert "recovered")
 *      - firing → firing AND cooldown expired (re-notify so it doesn't
 *        sit silent for hours if it keeps firing)
 *   4. Persist the new state.
 *
 * Notifications go via the nawasara/notification facade using an ad-hoc
 * subject+body — no template registration needed for MVP. When operators
 * want richer formatting, swap to ->template('database.alert').
 */
class AlertEvaluator
{
    public function evaluate(DbServer $server): array
    {
        if (! (bool) config('nawasara-database-monitor.alerts.enabled', true)) {
            return ['skipped' => 'alerts_disabled'];
        }

        $results = [];

        $results['server.unreachable'] = $this->evaluateUnreachable($server);
        $results['connections.high'] = $this->evaluateConnectionsHigh($server);
        $results['aborted.high'] = $this->evaluateAbortedHigh($server);

        return $results;
    }

    protected function evaluateUnreachable(DbServer $server): array
    {
        $firing = $server->status !== DbServer::STATUS_ONLINE;
        $message = $firing
            ? "Server {$server->label} status: {$server->status}".($server->status_message ? " — {$server->status_message}" : '')
            : null;

        return $this->transition($server, DbAlertState::RULE_SERVER_UNREACHABLE, $firing, $message);
    }

    protected function evaluateConnectionsHigh(DbServer $server): array
    {
        // We only have max_connections cached; current Threads_connected is
        // refreshed only via Performance/Index queries. For Day 5 we treat
        // this as best-effort: probe globalStatus on every alert tick. The
        // probe is one cheap row from performance_schema and bounded by
        // the alerts_interval (default 1 min).
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
        $message = $firing
            ? "Threads_connected = {$connected}/{$maxConn} ({$pct}% — threshold {$threshold}%)"
            : null;

        return $this->transition($server, DbAlertState::RULE_CONNECTIONS_HIGH, $firing, $message);
    }

    protected function evaluateAbortedHigh(DbServer $server): array
    {
        // Aborted_connects is a cumulative counter — we'd want delta-per-min
        // to alert meaningfully. For MVP we alert on absolute large jumps
        // since last evaluation; if no prior sample, just record and bail.
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
        // Stored on the server row to keep alert state separate.
        $server->forceFill(['last_aborted_connects' => $current])->saveQuietly();

        if ($previous === 0) {
            return ['state' => 'baseline_set', 'value' => $current];
        }

        $delta = max(0, $current - $previous);
        $firing = $delta >= $threshold;
        $message = $firing
            ? "Aborted_connects naik {$delta} sejak evaluasi terakhir (threshold {$threshold})"
            : null;

        return $this->transition($server, DbAlertState::RULE_ABORTED_HIGH, $firing, $message);
    }

    /**
     * Core transition logic. Returns the resulting state for telemetry.
     */
    protected function transition(DbServer $server, string $ruleKey, bool $firing, ?string $message): array
    {
        $state = DbAlertState::firstOrNew([
            'server_id' => $server->id,
            'rule_key' => $ruleKey,
        ]);

        $previousState = $state->state ?? DbAlertState::STATE_OK;
        $newState = $firing ? DbAlertState::STATE_FIRING : DbAlertState::STATE_OK;

        $shouldNotify = false;
        $kind = null;

        if ($previousState !== $newState) {
            // State change always notifies — "started" or "recovered".
            $shouldNotify = true;
            $kind = $firing ? 'started' : 'recovered';
            $state->firing_since = $firing ? now() : null;
        } elseif ($newState === DbAlertState::STATE_FIRING) {
            // Still firing — only notify after cooldown to avoid inbox flood.
            $cooldown = (int) config('nawasara-database-monitor.alerts.cooldown_minutes', 30);
            if ($state->cooldownExpired($cooldown)) {
                $shouldNotify = true;
                $kind = 'reminder';
            }
        }

        $state->state = $newState;
        $state->message = $message;

        if ($shouldNotify) {
            $sent = $this->sendNotification($server, $ruleKey, $kind, $message);
            if ($sent) {
                $state->last_notified_at = now();
            }
        }

        $state->save();

        return [
            'state' => $newState,
            'previous' => $previousState,
            'notified' => $shouldNotify,
            'kind' => $kind,
            'message' => $message,
        ];
    }

    /**
     * Fire an ad-hoc notification. Returns true on dispatch, false on any
     * failure (logged, never thrown — alert failure shouldn't crash sync).
     */
    protected function sendNotification(DbServer $server, string $ruleKey, ?string $kind, ?string $message): bool
    {
        $recipients = (array) config('nawasara-database-monitor.alerts.recipients', []);

        if (empty($recipients)) {
            Log::info('[database-monitor] alert triggered but no recipients configured', [
                'server' => $server->slug,
                'rule' => $ruleKey,
                'kind' => $kind,
                'message' => $message,
            ]);
            return false;
        }

        $label = match ($kind) {
            'started' => '[Alert] ',
            'recovered' => '[Recovered] ',
            'reminder' => '[Reminder] ',
            default => '[Alert] ',
        };

        $subject = $label.$server->label.' — '.$this->ruleLabel($ruleKey);
        $body = ($message ?? 'No additional context.')
            ."\n\nServer: {$server->label} ({$server->slug})"
            ."\nRule: {$ruleKey}"
            ."\nWaktu: ".now()->toDateTimeString();

        try {
            Notify::to(...$recipients)
                ->subject($subject)
                ->body($body)
                ->priority($kind === 'started' ? 'high' : 'normal')
                ->context([
                    'source' => 'database-monitor.alerts',
                    'server_id' => $server->id,
                    'rule_key' => $ruleKey,
                    'kind' => $kind,
                ])
                ->send();
            return true;
        } catch (\Throwable $e) {
            Log::error('[database-monitor] failed to send alert notification', [
                'error' => $e->getMessage(),
                'server' => $server->slug,
                'rule' => $ruleKey,
            ]);
            return false;
        }
    }

    protected function ruleLabel(string $ruleKey): string
    {
        return match ($ruleKey) {
            DbAlertState::RULE_SERVER_UNREACHABLE => 'Server tidak reachable',
            DbAlertState::RULE_CONNECTIONS_HIGH => 'Koneksi tinggi',
            DbAlertState::RULE_ABORTED_HIGH => 'Aborted connects melonjak',
            default => $ruleKey,
        };
    }
}
