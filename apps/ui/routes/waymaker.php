<?php

declare(strict_types=1);

use App\Http\Controllers\AgentDocsController;
use App\Http\Controllers\HomeController;
use Illuminate\Support\Facades\Route;

Route::get('/', [HomeController::class, 'show'])->name('HomeController.show');
Route::get('/conventions.md', [AgentDocsController::class, 'conventions'])->name('AgentDocsController.conventions');
Route::get('/create.md', [AgentDocsController::class, 'create'])->name('AgentDocsController.create');
Route::get('/herd.md', [AgentDocsController::class, 'herd'])->name('AgentDocsController.herd');
Route::get('/llms.txt', [AgentDocsController::class, 'index'])->name('AgentDocsController.index');
Route::get('/orbit.md', [AgentDocsController::class, 'orbit'])->name('AgentDocsController.orbit');
Route::get('/solo.md', [AgentDocsController::class, 'solo'])->name('AgentDocsController.solo');
