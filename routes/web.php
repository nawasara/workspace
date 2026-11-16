<?php

use Illuminate\Support\Facades\Route;
use Nawasara\Workspace\Livewire\Audit\Index as AuditIndex;
use Nawasara\Workspace\Livewire\Offboarding\Index as OffboardingIndex;
use Nawasara\Workspace\Livewire\Overview\Index as OverviewIndex;
use Nawasara\Workspace\Livewire\Pilot\Index as PilotIndex;
use Nawasara\Workspace\Livewire\Quota\Index as QuotaIndex;
use Spatie\Permission\Middleware\PermissionMiddleware;

Route::middleware(['web', 'auth'])->prefix('nawasara-workspace')->group(function () {
    Route::get('overview', OverviewIndex::class)
        ->middleware(PermissionMiddleware::using('workspace.view'))
        ->name('nawasara-workspace.overview');

    Route::get('pilot', PilotIndex::class)
        ->middleware(PermissionMiddleware::using('workspace.view'))
        ->name('nawasara-workspace.pilot');

    Route::get('quota', QuotaIndex::class)
        ->middleware(PermissionMiddleware::using('workspace.quota.view'))
        ->name('nawasara-workspace.quota');

    Route::get('offboarding', OffboardingIndex::class)
        ->middleware(PermissionMiddleware::using('workspace.offboarding.view'))
        ->name('nawasara-workspace.offboarding');

    Route::get('audit', AuditIndex::class)
        ->middleware(PermissionMiddleware::using('workspace.audit.view'))
        ->name('nawasara-workspace.audit');
});
