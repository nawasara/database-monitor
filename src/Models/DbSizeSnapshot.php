<?php

namespace Nawasara\DatabaseMonitor\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DbSizeSnapshot extends Model
{
    protected $table = 'nawasara_db_size_snapshots';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'data_size_bytes' => 'integer',
        'index_size_bytes' => 'integer',
        'table_count' => 'integer',
        'row_estimate' => 'integer',
        'captured_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function database(): BelongsTo
    {
        return $this->belongsTo(DbDatabase::class, 'database_id');
    }

    public function totalSizeBytes(): int
    {
        return (int) $this->data_size_bytes + (int) $this->index_size_bytes;
    }
}
