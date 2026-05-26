<?php

namespace Nawasara\DatabaseMonitor\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DbDatabase extends Model
{
    public const KIND_USER = 'user';

    public const KIND_SYSTEM = 'system';

    protected $table = 'nawasara_db_databases';

    protected $guarded = [];

    protected $casts = [
        'data_size_bytes' => 'integer',
        'index_size_bytes' => 'integer',
        'table_count' => 'integer',
        'row_estimate' => 'integer',
        'last_synced_at' => 'datetime',
    ];

    public function server(): BelongsTo
    {
        return $this->belongsTo(DbServer::class, 'server_id');
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(DbSizeSnapshot::class, 'database_id');
    }

    public function isSystem(): bool
    {
        return $this->kind === self::KIND_SYSTEM;
    }

    public function totalSizeBytes(): int
    {
        return (int) $this->data_size_bytes + (int) $this->index_size_bytes;
    }
}
