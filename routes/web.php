<?php

use Illuminate\Support\Facades\Route;
use Nawasara\Workspace\Livewire\Overview\Index as OverviewIndex;
use Nawasara\Workspace\Livewire\Pilot\Index as PilotIndex;
use Spatie\Permission\Middleware\PermissionMiddleware;

Route::middleware(['web', 'auth'])->prefix('nawasara-workspace')->group(function () {
    Route::get('overview', OverviewIndex::class)
        ->middleware(PermissionMiddleware::using('workspace.view'))
        ->name('nawasara-workspace.overview');

    Route::get('pilot', PilotIndex::class)
        ->middleware(PermissionMiddleware::using('workspace.view'))
        ->name('nawasara-workspace.pilot');
});
