<?php

use Illuminate\Support\Facades\Route;
use Nawasara\Workspace\Http\Api\AccessController;

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
