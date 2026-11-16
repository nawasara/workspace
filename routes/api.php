<?php

use Illuminate\Support\Facades\Route;
use Nawasara\Workspace\Http\Api\AccessController;
use Nawasara\Workspace\Http\Api\DirectoryController;
use Nawasara\Workspace\Http\Api\QuotaController;
use Nawasara\Workspace\Http\Api\TemplateController;

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

// "Mulai ... baru" ribbon of one employee (FR-16 AC-4, T-31).
Route::middleware('scope:workspace.template.read')->group(function () {
    Route::get('/templates', [TemplateController::class, 'index'])->name('templates.index');
});

// Kelola Templat (T-32). The scope proves the caller is Workspace Web; the
// `actor` NIP must also be admin Kisara, checked in the controller.
Route::middleware('scope:workspace.template.manage')->group(function () {
    Route::get('/templates/manage', [TemplateController::class, 'manage'])->name('templates.manage');
    Route::post('/templates', [TemplateController::class, 'store'])->name('templates.store');
    Route::patch('/templates/{id}', [TemplateController::class, 'update'])->where('id', '[^/]{1,64}')->name('templates.update');
    Route::post('/templates/{id}/file', [TemplateController::class, 'replaceFile'])->where('id', '[^/]{1,64}')->name('templates.file');
    Route::delete('/templates/{id}', [TemplateController::class, 'destroy'])->where('id', '[^/]{1,64}')->name('templates.destroy');
});
