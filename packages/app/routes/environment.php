<?php

use HardImpact\Orbit\Ui\Http\Controllers\DnsController;
use HardImpact\Orbit\Ui\Http\Controllers\EnvironmentController;
use Illuminate\Support\Facades\Route;

Route::post('set-default', [EnvironmentController::class, 'setDefault'])->name('environments.set-default');

// Note: test-connection, status, projects, config, worktrees moved to routes/api.php (stateless)

// Doctor (health checks)
Route::get('doctor', [EnvironmentController::class, 'runDoctor'])->name('environments.doctor');
Route::get('doctor/quick', [EnvironmentController::class, 'quickCheck'])->name('environments.doctor.quick');
Route::post('doctor/fix/{check}', [EnvironmentController::class, 'fixDoctorIssue'])->name('environments.doctor.fix');

// Environment pages
Route::get('projects', [EnvironmentController::class, 'projectsPage'])->name('environments.projects');
Route::get('services', [EnvironmentController::class, 'servicesPage'])->name('environments.services');
Route::get('configuration', [EnvironmentController::class, 'settings'])->name('environments.configuration');
Route::post('configuration', [EnvironmentController::class, 'updateSettings'])->name('environments.configuration.update');
Route::post('configuration/external-access', [EnvironmentController::class, 'updateExternalAccess'])->name('environments.configuration.external-access');
// Backwards compatibility: redirect old settings URLs
Route::redirect('settings', 'configuration');
Route::get('settings/{any?}', fn () => redirect()->route('environments.configuration'))->where('any', '.*');

// Note: api/projects moved to routes/api.php (stateless)
Route::post('start', [EnvironmentController::class, 'start'])->name('environments.start');
Route::post('stop', [EnvironmentController::class, 'stop'])->name('environments.stop');
Route::post('restart', [EnvironmentController::class, 'restart'])->name('environments.restart');
Route::post('php', [EnvironmentController::class, 'changePhp'])->name('environments.php');
Route::post('php/reset', [EnvironmentController::class, 'resetPhp'])->name('environments.php.reset');

// Individual service routes
Route::get('services/available', [EnvironmentController::class, 'availableServices'])->name('environments.services.available');
Route::post('services/{service}/start', [EnvironmentController::class, 'startService'])->name('environments.services.start');
Route::post('services/{service}/stop', [EnvironmentController::class, 'stopService'])->name('environments.services.stop');
Route::post('services/{service}/restart', [EnvironmentController::class, 'restartService'])->name('environments.services.restart');
Route::post('host-services/{service}/start', [EnvironmentController::class, 'startHostService'])->name('environments.host-services.start');
Route::post('host-services/{service}/stop', [EnvironmentController::class, 'stopHostService'])->name('environments.host-services.stop');
Route::post('host-services/{service}/restart', [EnvironmentController::class, 'restartHostService'])->name('environments.host-services.restart');
Route::get('services/{service}/logs', [EnvironmentController::class, 'serviceLogs'])->name('environments.services.logs');
Route::post('services/{service}/enable', [EnvironmentController::class, 'enableService'])->name('environments.services.enable');
Route::delete('services/{service}', [EnvironmentController::class, 'disableService'])->name('environments.services.disable');
Route::put('services/{service}/config', [EnvironmentController::class, 'configureService'])->name('environments.services.config');
Route::get('services/{service}/info', [EnvironmentController::class, 'serviceInfo'])->name('environments.services.info');

// Note: GET config and worktrees moved to routes/api.php (stateless)
Route::post('config', [EnvironmentController::class, 'saveConfig'])->name('environments.config.save');
Route::get('browse-directories', [EnvironmentController::class, 'browseDirectories'])->name('environments.browse-directories');
Route::get('reverb-config', [EnvironmentController::class, 'getReverbConfig'])->name('environments.reverb-config');

// Worktree modification routes (need session for CSRF)
Route::post('worktrees/unlink', [EnvironmentController::class, 'unlinkWorktree'])->name('environments.worktrees.unlink');
Route::post('worktrees/refresh', [EnvironmentController::class, 'refreshWorktrees'])->name('environments.worktrees.refresh');

// Project routes
Route::get('projects/create', [EnvironmentController::class, 'createProject'])->name('environments.projects.create');
Route::post('projects', [EnvironmentController::class, 'storeProject'])->name('environments.projects.store');
Route::delete('projects/{projectName}', [EnvironmentController::class, 'destroyProject'])->name('environments.projects.destroy');
Route::post('projects/{projectName}/rebuild', [EnvironmentController::class, 'rebuildProject'])->name('environments.projects.rebuild');
Route::get('projects/{projectSlug}/provision-status', [EnvironmentController::class, 'provisionStatus'])->name('environments.projects.provision-status');
Route::post('template-defaults', [EnvironmentController::class, 'templateDefaults'])->name('environments.template-defaults');
Route::get('github-user', [EnvironmentController::class, 'githubUser'])->name('environments.github-user');
Route::get('github-orgs', [EnvironmentController::class, 'githubOrgs'])->name('environments.github-orgs');
Route::post('github-repo-exists', [EnvironmentController::class, 'githubRepoExists'])->name('environments.github-repo-exists');

// DNS mapping routes
Route::get('dns', [DnsController::class, 'index'])->name('environments.dns.index');
Route::post('dns', [DnsController::class, 'update'])->name('environments.dns.update');

// Workspace routes (API endpoints moved to routes/api.php for stateless access)
Route::get('workspaces', [EnvironmentController::class, 'workspaces'])->name('environments.workspaces');
Route::get('workspaces/create', [EnvironmentController::class, 'createWorkspace'])->name('environments.workspaces.create');
Route::post('workspaces', [EnvironmentController::class, 'storeWorkspace'])->name('environments.workspaces.store');
Route::get('workspaces/{workspace}', [EnvironmentController::class, 'showWorkspace'])->name('environments.workspaces.show');
Route::delete('workspaces/{workspace}', [EnvironmentController::class, 'destroyWorkspace'])->name('environments.workspaces.destroy');
Route::post('workspaces/{workspace}/projects', [EnvironmentController::class, 'addWorkspaceProject'])->name('environments.workspaces.projects.add');
Route::delete('workspaces/{workspace}/projects/{project}', [EnvironmentController::class, 'removeWorkspaceProject'])->name('environments.workspaces.projects.remove');

// Package linking routes
Route::get('projects/{project}/linked-packages', [EnvironmentController::class, 'linkedPackages'])->name('environments.projects.linked-packages');
Route::post('projects/{project}/link-package', [EnvironmentController::class, 'linkPackage'])->name('environments.projects.link-package');
Route::delete('projects/{project}/unlink-package/{package}', [EnvironmentController::class, 'unlinkPackage'])->name('environments.projects.unlink-package');
