<?php

use App\Services\DatabaseService;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    // Create an in-memory SQLite database for testing
    config(['database.default' => 'testing']);
    config(['database.connections.testing' => [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]]);

    // Create the projects table
    Schema::create('projects', function ($table) {
        $table->id();
        $table->string('slug')->unique();
        $table->string('path');
        $table->string('php_version')->nullable();
        $table->timestamps();
    });

    $this->db = app(DatabaseService::class);
});

afterEach(function () {
    Schema::dropIfExists('projects');
});

it('stores and retrieves site PHP version', function () {
    $this->db->setSitePhpVersion('my-site', '/path/to/site', '8.4');

    $version = $this->db->getPhpVersion('my-site');
    expect($version)->toBe('8.4');
});

it('returns null for non-existent site', function () {
    $version = $this->db->getPhpVersion('non-existent');
    expect($version)->toBeNull();
});

it('updates existing site', function () {
    $this->db->setSitePhpVersion('my-site', '/path/to/site', '8.3');
    $this->db->setSitePhpVersion('my-site', '/path/to/site', '8.4');

    $version = $this->db->getPhpVersion('my-site');
    expect($version)->toBe('8.4');
});

it('retrieves full site override', function () {
    $this->db->setSitePhpVersion('my-site', '/path/to/site', '8.4');

    $override = $this->db->getSiteOverride('my-site');

    expect($override)->not->toBeNull();
    expect($override['slug'])->toBe('my-site');
    expect($override['path'])->toBe('/path/to/site');
    expect($override['php_version'])->toBe('8.4');
});

it('removes site override', function () {
    $this->db->setSitePhpVersion('my-site', '/path/to/site', '8.4');
    $this->db->removeSiteOverride('my-site');

    $override = $this->db->getSiteOverride('my-site');
    expect($override)->toBeNull();
});

it('returns all overrides', function () {
    $this->db->setSitePhpVersion('site-1', '/path/1', '8.3');
    $this->db->setSitePhpVersion('site-2', '/path/2', '8.4');
    $this->db->setSitePhpVersion('site-3', '/path/3', null);

    $overrides = $this->db->getAllOverrides();

    // Only sites with php_version set are returned
    expect($overrides)->toHaveCount(2);
});

it('truncates all data', function () {
    $this->db->setSitePhpVersion('site-1', '/path/1', '8.3');
    $this->db->setSitePhpVersion('site-2', '/path/2', '8.4');

    $this->db->truncate();

    $overrides = $this->db->getAllOverrides();
    expect($overrides)->toHaveCount(0);
});
