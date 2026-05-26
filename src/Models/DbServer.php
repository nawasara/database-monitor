<?php

namespace Nawasara\DatabaseMonitor\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DbServer extends Model
{
    public const STATUS_ONLINE = 'online';

    public const STATUS_UNREACHABLE = 'unreachable';

    public const STATUS_DEGRADED = 'degraded';

    public const STATUS_UNKNOWN = 'unknown';

    public const SLUG_DEFAULT = 'kominfo-central';

    protected $table = 'nawasara_db_servers';

    protected $guarded = [];

    protected $casts = [
        'uptime_seconds' => 'integer',
        'max_connections' => 'integer',
        'database_count' => 'integer',
        'last_synced_at' => 'datetime',
    ];

    public function databases(): HasMany
    {
        return $this->hasMany(DbDatabase::class, 'server_id');
    }

    public function isOnline(): bool
    {
        return $this->status === self::STATUS_ONLINE;
    }
}
