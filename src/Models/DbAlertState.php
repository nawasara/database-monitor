<?php

namespace Nawasara\DatabaseMonitor\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DbAlertState extends Model
{
    public const STATE_OK = 'ok';

    public const STATE_FIRING = 'firing';

    /**
     * Rule keys. Used as the second column of the (server_id, rule_key)
     * natural identity. Adding a new rule means adding a constant here
     * AND a corresponding evaluator method in AlertEvaluator.
     */
    public const RULE_SERVER_UNREACHABLE = 'server.unreachable';

    public const RULE_CONNECTIONS_HIGH = 'connections.high';

    public const RULE_ABORTED_HIGH = 'aborted.high';

    protected $table = 'nawasara_db_alert_states';

    protected $guarded = [];

    protected $casts = [
        'last_notified_at' => 'datetime',
        'firing_since' => 'datetime',
    ];

    public function server(): BelongsTo
    {
        return $this->belongsTo(DbServer::class, 'server_id');
    }

    public function isFiring(): bool
    {
        return $this->state === self::STATE_FIRING;
    }

    /**
     * Whether enough time has elapsed since the last notification that we
     * may send another one for this rule. State-change events bypass this
     * check — they always notify.
     */
    public function cooldownExpired(int $minutes): bool
    {
        if (! $this->last_notified_at) {
            return true;
        }

        return $this->last_notified_at->lt(now()->subMinutes($minutes));
    }
}
