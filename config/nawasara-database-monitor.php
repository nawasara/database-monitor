<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Sync intervals (menit)
    |--------------------------------------------------------------------------
    | Inventory (daftar database + server info) jarang berubah — 15 menit.
    | Metrics (ukuran + processlist) lebih sering — 5 menit.
    | Alerts evaluasi — 1 menit (cheap, baca cache lokal).
    */
    'sync_interval' => env('DB_MONITOR_SYNC_INTERVAL', 15),
    'metrics_interval' => env('DB_MONITOR_METRICS_INTERVAL', 5),
    'alerts_interval' => env('DB_MONITOR_ALERTS_INTERVAL', 1),

    /*
    |--------------------------------------------------------------------------
    | Koneksi MySQL target
    |--------------------------------------------------------------------------
    | Defensive timeout — kalau server target hang, jangan blok queue worker.
    */
    'connection_timeout' => env('DB_MONITOR_CONN_TIMEOUT', 5),
    'query_timeout' => env('DB_MONITOR_QUERY_TIMEOUT', 10),

    /*
    |--------------------------------------------------------------------------
    | System databases yang di-hide di UI per default
    |--------------------------------------------------------------------------
    | Bisa di-toggle per user lewat filter di Livewire — defensive default
    | supaya dashboard tidak penuh sama internal MySQL.
    */
    'system_databases' => [
        'information_schema',
        'performance_schema',
        'mysql',
        'sys',
    ],

    /*
    |--------------------------------------------------------------------------
    | Alert thresholds (Fase E)
    |--------------------------------------------------------------------------
    | Default — bisa di-override per server di Vault metadata atau UI.
    */
    'alerts' => [
        'enabled' => env('DB_MONITOR_ALERTS_ENABLED', true),

        // Recipients — comma-separated emails di .env, atau hardcode di sini
        // untuk dev. Empty array = alert evaluation tetap jalan (state masih
        // di-track) tapi tidak ada email yang terkirim (log warning saja).
        'recipients' => array_filter(array_map('trim', explode(',', (string) env('DB_MONITOR_ALERT_RECIPIENTS', '')))),

        // Thresholds — default-nya konservatif. Operator bisa override per
        // environment via .env.
        'connections_pct' => (int) env('DB_MONITOR_ALERT_CONN_PCT', 80),    // % dari max_connections
        'aborted_connects_per_min' => (int) env('DB_MONITOR_ALERT_ABORTED_PER_MIN', 10),

        // Anti-spam: setelah alert untuk 1 kondisi terkirim, jangan kirim
        // ulang sampai cooldown habis. State-change (mis. unreachable →
        // online) trigger "recovered" alert sekali, lalu reset cooldown.
        'cooldown_minutes' => (int) env('DB_MONITOR_ALERT_COOLDOWN', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Fase F — Administration (CREATE/DROP database, manage user)
    |--------------------------------------------------------------------------
    | Default OFF. Aktifkan ketat — package jadi punya fitur write yang
    | tidak bisa di-undo (DROP DATABASE).
    */
    'admin' => [
        'enabled' => env('DB_MONITOR_ADMIN_ENABLED', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Scheduler
    |--------------------------------------------------------------------------
    | Set DB_MONITOR_SCHEDULER_ENABLED=false di deployment yang belum punya
    | kredensial Vault — supaya scheduled task tidak fail tiap run.
    */
    'scheduler' => [
        'enabled' => env('DB_MONITOR_SCHEDULER_ENABLED', true),
    ],
];
