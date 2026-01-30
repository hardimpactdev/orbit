<?php

use HardImpact\Orbit\Core\Models\TemplateFavorite;

describe('Settings Page', function () {
    test('can view settings page', function () {
        $this->visit('/settings')
            ->assertSee('Settings')
            ->assertSee('Editor');
    });

    test('can see template favorites section', function () {
        TemplateFavorite::create([
            'repo_url' => 'laravel/laravel',
            'display_name' => 'Laravel',
        ]);

        $this->visit('/settings')
            ->assertSee('Template Favorites')
            ->assertSee('Laravel');
    });

    test('shows terminal preference', function () {
        $this->visit('/settings')
            ->assertSee('Terminal');
    });
});
