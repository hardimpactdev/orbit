<?php

declare(strict_types=1);

use App\Http\Middleware\RequireActiveWireGuardPeer;
use App\Http\Middleware\RequireEnabledExtension;
use App\Http\Middleware\RequireNodeAccess;
use Illuminate\Support\Facades\Route;
use Orbit\Extensions\Documents\Http\Controllers\ProjectDocumentsController;
use Orbit\Extensions\Documents\Http\Controllers\ProjectDocumentStorageController;

Route::middleware([
    'api',
    RequireActiveWireGuardPeer::class,
    RequireNodeAccess::class,
    RequireEnabledExtension::class.':documents',
])->prefix('api/v1')->group(function (): void {
    Route::get('project-document-storage', [ProjectDocumentStorageController::class, 'show'])
        ->name('project:document-storage:show');
    Route::put('project-document-storage', [ProjectDocumentStorageController::class, 'update'])
        ->name('project:document-storage:update');
    Route::prefix('projects/{project}/documents')->whereNumber(['project', 'entry'])->group(function (): void {
        Route::get('', [ProjectDocumentsController::class, 'index'])->name('project:document:list');
        Route::get('search', [ProjectDocumentsController::class, 'search'])->name('project:document:search');
        Route::post('', [ProjectDocumentsController::class, 'store'])->name('project:document:create');
        Route::get('{entry}', [ProjectDocumentsController::class, 'show'])->name('project:document:show');
        Route::patch('{entry}', [ProjectDocumentsController::class, 'update'])->name('project:document:update');
        Route::put('{entry}/content', [ProjectDocumentsController::class, 'write'])->name('project:document:write');
        Route::get('{entry}/content', [ProjectDocumentsController::class, 'read'])->name('project:document:read');
        Route::get('{entry}/download', [ProjectDocumentsController::class, 'download'])->name('project:document:download');
        Route::get('{entry}/versions', [ProjectDocumentsController::class, 'versions'])->name('project:document:version:list');
        Route::post('{entry}/restore-version', [ProjectDocumentsController::class, 'restoreVersion'])->name('project:document:restore-version');
        Route::post('{entry}/archive', [ProjectDocumentsController::class, 'archive'])->name('project:document:archive');
        Route::post('{entry}/restore', [ProjectDocumentsController::class, 'restore'])->name('project:document:restore');
        Route::delete('{entry}', [ProjectDocumentsController::class, 'destroy'])->name('project:document:destroy');
    });
});
