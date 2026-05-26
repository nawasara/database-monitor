<?php

$prefix = 'nawasara-database-monitor';

$submenu = [
    [
        'label' => 'Dashboard',
        'icon' => 'lucide-layout-dashboard',
        'url' => url($prefix.'/dashboard'),
        'permission' => 'database-monitor.view',
        'navigate' => true,
    ],
    [
        'label' => 'Performance',
        'icon' => 'lucide-activity',
        'url' => url($prefix.'/performance'),
        'permission' => 'database-monitor.metrics.view',
        'navigate' => true,
    ],
];

// Admin entries only surface in the sidebar when administration mode is
// enabled at the config layer. Hiding them in menu UI is purely an
// affordance — the routes always 404 from mount() when admin.enabled
// is false, regardless of permission.
if (config('nawasara-database-monitor.admin.enabled', false)) {
    $submenu[] = [
        'label' => 'Admin · Databases',
        'icon' => 'lucide-database-zap',
        'url' => url($prefix.'/admin/databases'),
        'permission' => 'database-monitor.database.create',
        'navigate' => true,
    ];

    $submenu[] = [
        'label' => 'Admin · Users',
        'icon' => 'lucide-users-round',
        'url' => url($prefix.'/admin/users'),
        'permission' => 'database-monitor.user.manage',
        'navigate' => true,
    ];
}

return [
    [
        'workspace' => 'database',
        'label' => 'Database',
        'icon' => 'lucide-database',
        'url' => '',
        'permission' => 'database-monitor.view',
        'submenu' => $submenu,
    ],
];
