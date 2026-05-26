<?php

use Illuminate\Support\Facades\Route;
use Nawasara\DatabaseMonitor\Livewire\Admin\DatabaseIndex as AdminDatabaseIndex;
use Nawasara\DatabaseMonitor\Livewire\Admin\UserIndex as AdminUserIndex;
use Nawasara\DatabaseMonitor\Livewire\Dashboard\Index as DashboardIndex;
use Nawasara\DatabaseMonitor\Livewire\Performance\Index as PerformanceIndex;
use Spatie\Permission\Middleware\PermissionMiddleware;

Route::middleware(['web', 'auth'])->prefix('nawasara-database-monitor')->group(function () {
    Route::get('dashboard', DashboardIndex::class)
        ->middleware(PermissionMiddleware::using('database-monitor.view'))
        ->name('nawasara-database-monitor.dashboard');

    Route::get('performance', PerformanceIndex::class)
        ->middleware(PermissionMiddleware::using('database-monitor.metrics.view'))
        ->name('nawasara-database-monitor.performance');

    // Fase F — Administration. Routes registered unconditionally so menu
    // generation can reference them, but the Livewire mount() check + the
    // admin_enabled config flag inside the component is the actual gate
    // against accidental write access on misconfigured deployments.
    Route::prefix('admin')->group(function () {
        Route::get('databases', AdminDatabaseIndex::class)
            ->middleware(PermissionMiddleware::using('database-monitor.database.create'))
            ->name('nawasara-database-monitor.admin.databases');

        Route::get('users', AdminUserIndex::class)
            ->middleware(PermissionMiddleware::using('database-monitor.user.manage'))
            ->name('nawasara-database-monitor.admin.users');
    });
});
