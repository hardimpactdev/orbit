<?php

use HardImpact\Orbit\Ui\Http\Controllers\DnsController;
use HardImpact\Orbit\Ui\Http\Controllers\EnvironmentConfigController;
use HardImpact\Orbit\Ui\Http\Controllers\EnvironmentController;
use HardImpact\Orbit\Ui\Http\Controllers\EnvironmentProjectController;
use HardImpact\Orbit\Ui\Http\Controllers\EnvironmentServiceController;
use HardImpact\Orbit\Ui\Http\Controllers\EnvironmentStatusController;
use HardImpact\Orbit\Ui\Http\Controllers\PackageController;
use HardImpact\Orbit\Ui\Http\Controllers\PhpConfigController;
use HardImpact\Orbit\Ui\Http\Controllers\WorkspaceController;
use HardImpact\Orbit\Ui\Http\Controllers\WorktreeController;
use Illuminate\Support\Facades\Route;

Route::post('set-default', [EnvironmentController::class, 'setDefault'])->name('environments.set-default');

// Note: test-connection, status, projects, config, worktrees moved to routes/api.php (stateless)

// Doctor (health checks)
Route::get('doctor', [EnvironmentStatusController::class, 'runDoctor'])->name('environments.doctor');
Route::get('doctor/quick', [EnvironmentStatusController::class, 'quickCheck'])->name('environments.doctor.quick');
Route::post('doctor/fix/{check}', [EnvironmentStatusController::class, 'fixIssue'])->name('environments.doctor.fix');

// Environment pages
Route::get('projects', [EnvironmentProjectController::class, 'index'])->name('environments.projects');
Route::get('services', [EnvironmentController::class, 'servicesPage'])->name('environments.services');
Route::get('configuration', [EnvironmentController::class, 'settings'])->name('environments.configuration');
Route::post('configuration', [EnvironmentController::class, 'updateSettings'])->name('environments.configuration.update');
Route::post('configuration/external-access', [EnvironmentController::class, 'updateExternalAccess'])->name('environments.configuration.external-access');
// Backwards compatibility: redirect old settings URLs
Route::redirect('settings', 'configuration');
Route::get('settings/{any?}', fn () => redirect()->route('environments.configuration'))->where('any', '.*');

// Note: api/projects moved to routes/api.php (stateless)
Route::post('start', [EnvironmentServiceController::class, 'start'])->name('environments.start');
Route::post('stop', [EnvironmentServiceController::class, 'stop'])->name('environments.stop');
Route::post('restart', [EnvironmentServiceController::class, 'restart'])->name('environments.restart');
Route::post('php', [PhpConfigController::class, 'changePhp'])->name('environments.php');
Route::post('php/reset', [PhpConfigController::class, 'resetPhp'])->name('environments.php.reset');

// Individual service routes
Route::get('services/available', [EnvironmentServiceController::class, 'availableServices'])->name('environments.services.available');
Route::post('services/{service}/start', [EnvironmentServiceController::class, 'startService'])->name('environments.services.start');
Route::post('services/{service}/stop', [EnvironmentServiceController::class, 'stopService'])->name('environments.services.stop');
Route::post('services/{service}/restart', [EnvironmentServiceController::class, 'restartService'])->name('environments.services.restart');
Route::post('host-services/{service}/start', [EnvironmentServiceController::class, 'startHostService'])->name('environments.host-services.start');
Route::post('host-services/{service}/stop', [EnvironmentServiceController::class, 'stopHostService'])->name('environments.host-services.stop');
Route::post('host-services/{service}/restart', [EnvironmentServiceController::class, 'restartHostService'])->name('environments.host-services.restart');
Route::get('services/{service}/logs', [EnvironmentServiceController::class, 'serviceLogs'])->name('environments.services.logs');
Route::post('services/{service}/enable', [EnvironmentServiceController::class, 'enableService'])->name('environments.services.enable');
Route::delete('services/{service}', [EnvironmentServiceController::class, 'disableService'])->name('environments.services.disable');
Route::put('services/{service}/config', [EnvironmentServiceController::class, 'configureService'])->name('environments.services.config');
Route::get('services/{service}/info', [EnvironmentServiceController::class, 'serviceInfo'])->name('environments.services.info');

// Note: GET config and worktrees moved to routes/api.php (stateless)
Route::get('config', [EnvironmentConfigController::class, 'getConfig'])->name('environments.config');
Route::post('config', [EnvironmentConfigController::class, 'saveConfig'])->name('environments.config.save');
Route::get('browse-directories', [EnvironmentConfigController::class, 'browseDirectories'])->name('environments.browse-directories');
Route::get('reverb-config', [EnvironmentConfigController::class, 'getReverbConfig'])->name('environments.reverb-config');

// Worktree modification routes (need session for CSRF)
Route::post('worktrees/unlink', [WorktreeController::class, 'unlink'])->name('environments.worktrees.unlink');
Route::post('worktrees/refresh', [WorktreeController::class, 'refresh'])->name('environments.worktrees.refresh');

// Project routes
Route::get('projects/create', [EnvironmentProjectController::class, 'create'])->name('environments.projects.create');
Route::post('projects', [EnvironmentProjectController::class, 'store'])->name('environments.projects.store');
Route::delete('projects/{projectName}', [EnvironmentProjectController::class, 'destroy'])->name('environments.projects.destroy');
Route::post('projects/{projectName}/rebuild', [EnvironmentProjectController::class, 'rebuild'])->name('environments.projects.rebuild');
Route::get('projects/{projectSlug}/provision-status', [EnvironmentProjectController::class, 'provisionStatus'])->name('environments.projects.provision-status');
Route::post('template-defaults', [EnvironmentProjectController::class, 'templateDefaults'])->name('environments.template-defaults');
Route::get('github-user', [EnvironmentProjectController::class, 'githubUser'])->name('environments.github-user');
Route::get('github-orgs', [EnvironmentProjectController::class, 'githubOrgs'])->name('environments.github-orgs');
Route::post('github-repo-exists', [EnvironmentProjectController::class, 'githubRepoExists'])->name('environments.github-repo-exists');

// DNS mapping routes
Route::get('dns', [DnsController::class, 'index'])->name('environments.dns.index');
Route::post('dns', [DnsController::class, 'update'])->name('environments.dns.update');

// Workspace routes (API endpoints moved to routes/api.php for stateless access)
Route::get('workspaces', [WorkspaceController::class, 'index'])->name('environments.workspaces');
Route::get('workspaces/create', [WorkspaceController::class, 'create'])->name('environments.workspaces.create');
Route::post('workspaces', [WorkspaceController::class, 'store'])->name('environments.workspaces.store');
Route::get('workspaces/{workspace}', [WorkspaceController::class, 'show'])->name('environments.workspaces.show');
Route::delete('workspaces/{workspace}', [WorkspaceController::class, 'destroy'])->name('environments.workspaces.destroy');
Route::post('workspaces/{workspace}/projects', [WorkspaceController::class, 'addProject'])->name('environments.workspaces.projects.add');
Route::delete('workspaces/{workspace}/projects/{project}', [WorkspaceController::class, 'removeProject'])->name('environments.workspaces.projects.remove');

// Package linking routes
Route::get('projects/{project}/linked-packages', [PackageController::class, 'index'])->name('environments.projects.linked-packages');
Route::post('projects/{project}/link-package', [PackageController::class, 'link'])->name('environments.projects.link-package');
Route::delete('projects/{project}/unlink-package/{package}', [PackageController::class, 'unlink'])->name('environments.projects.unlink-package');
