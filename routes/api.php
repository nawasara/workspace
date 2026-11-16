<?php

use Illuminate\Support\Facades\Route;
use Nawasara\Workspace\Http\Api\AccessController;
use Nawasara\Workspace\Http\Api\DirectoryController;
use Nawasara\Workspace\Http\Api\QuotaController;

/*
| Workspace API, mounted by WorkspaceServiceProvider at /api/v1/workspace with
| api + api.auth + api.log. Callers are Kisara services with a service token
| (Workspace Web, Kisara Akun), never browsers.
*/

Route::middleware('scope:workspace.access.read')->group(function () {
    Route::get('/access/{nip}', [AccessController::class, 'show'])
        ->where('nip', '[0-9]{1,32}')
        ->name('access.show');
});

// Bagikan and @mention (T-25). Allow-list fields only, never e-mail or phone.
Route::middleware('scope:workspace.directory.read')->group(function () {
    Route::get('/directory', [DirectoryController::class, 'index'])->name('directory.index');
});

// Penyimpanan page of Kisara Akun (T-29). Any segment reaches the controller,
// so a malformed NIP gets a 422 instead of a bare 404.
Route::middleware('scope:workspace.quota.read')->group(function () {
    Route::get('/quota/{nip}', [QuotaController::class, 'show'])
        ->where('nip', '[^/]{1,64}')
        ->name('quota.show');
});
