<?php

use HardImpact\Orbit\Core\Models\Environment;
use HardImpact\Orbit\Core\Models\TemplateFavorite;
use HardImpact\Orbit\Core\Models\UserPreference;

beforeEach(function () {
    createEnvironment();
});

test('settings page loads', function () {
    $environment = Environment::first();

    $response = $this->get("/environments/{$environment->id}/settings");

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page->component('environments/Settings'));
});

test('settings page includes template favorites', function () {
    $environment = Environment::first();

    TemplateFavorite::create([
        'repo_url' => 'laravel/laravel',
        'display_name' => 'Laravel',
        'usage_count' => 5,
    ]);

    $response = $this->get("/environments/{$environment->id}/settings");

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page
        ->has('templateFavorites', 1)
        ->where('templateFavorites.0.display_name', 'Laravel'));
});

test('template favorite can be created', function () {
    $response = $this->post('/template-favorites', [
        'repo_url' => 'laravel/laravel',
        'display_name' => 'Laravel',
    ]);

    $response->assertRedirect('/settings');
    $this->assertDatabaseHas('template_favorites', [
        'repo_url' => 'laravel/laravel',
        'display_name' => 'Laravel',
    ]);
});

test('template favorite requires unique repo url', function () {
    TemplateFavorite::create([
        'repo_url' => 'laravel/laravel',
        'display_name' => 'Laravel',
        'usage_count' => 0,
    ]);

    $response = $this->post('/template-favorites', [
        'repo_url' => 'laravel/laravel',
        'display_name' => 'Laravel Duplicate',
    ]);

    $response->assertSessionHasErrors('repo_url');
    $this->assertDatabaseCount('template_favorites', 1);
});

test('template favorite can be updated', function () {
    $template = TemplateFavorite::create([
        'repo_url' => 'laravel/laravel',
        'display_name' => 'Laravel',
        'usage_count' => 0,
    ]);

    $response = $this->put("/template-favorites/{$template->id}", [
        'display_name' => 'Laravel Framework',
    ]);

    $response->assertRedirect('/settings');
    $this->assertDatabaseHas('template_favorites', [
        'id' => $template->id,
        'display_name' => 'Laravel Framework',
    ]);
});

test('template favorite can be deleted', function () {
    $template = TemplateFavorite::create([
        'repo_url' => 'laravel/laravel',
        'display_name' => 'Laravel',
        'usage_count' => 0,
    ]);

    $response = $this->delete("/template-favorites/{$template->id}");

    $response->assertRedirect('/settings');
    $this->assertDatabaseMissing('template_favorites', ['id' => $template->id]);
});

test('settings page includes notification preference', function () {
    $environment = Environment::first();

    $response = $this->get("/environments/{$environment->id}/settings");

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page->has('notificationsEnabled'));
});

test('notifications can be disabled', function () {
    $response = $this->post('/settings/notifications', [
        'enabled' => false,
    ]);

    $response->assertRedirect('/settings');
    $this->assertDatabaseHas('user_preferences', [
        'key' => 'notifications_enabled',
    ]);

    $pref = UserPreference::where('key', 'notifications_enabled')->first();
    expect($pref->value)->toBeFalse();
});

test('notifications can be enabled', function () {
    UserPreference::setValue('notifications_enabled', false);

    $response = $this->post('/settings/notifications', [
        'enabled' => true,
    ]);

    $response->assertRedirect('/settings');

    $pref = UserPreference::where('key', 'notifications_enabled')->first();
    expect($pref->value)->toBeTrue();
});

test('settings page includes menu bar preference', function () {
    $environment = Environment::first();

    $response = $this->get("/environments/{$environment->id}/settings");

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page->has('menuBarEnabled'));
});

test('menu bar can be enabled', function () {
    $response = $this->post('/settings/menu-bar', [
        'enabled' => true,
    ]);

    $response->assertRedirect('/settings');
    $this->assertDatabaseHas('user_preferences', [
        'key' => 'menu_bar_enabled',
    ]);

    $pref = UserPreference::where('key', 'menu_bar_enabled')->first();
    expect($pref->value)->toBeTrue();
});

test('menu bar can be disabled', function () {
    UserPreference::setValue('menu_bar_enabled', true);

    $response = $this->post('/settings/menu-bar', [
        'enabled' => false,
    ]);

    $response->assertRedirect('/settings');

    $pref = UserPreference::where('key', 'menu_bar_enabled')->first();
    expect($pref->value)->toBeFalse();
});
