<?php

use HardImpact\Orbit\Core\Models\TemplateFavorite;

test('template favorite can be created', function () {
    TemplateFavorite::create([
        'repo_url' => 'owner/template-repo',
        'display_name' => 'template-repo',
    ]);

    $this->assertDatabaseHas('template_favorites', [
        'repo_url' => 'owner/template-repo',
        'display_name' => 'template-repo',
    ]);
});

test('record usage increments count', function () {
    $template = TemplateFavorite::create([
        'repo_url' => 'owner/template-repo',
        'display_name' => 'template-repo',
        'usage_count' => 0,
    ]);

    $template->recordUsage();
    $template->refresh();

    expect($template->usage_count)->toBe(1)
        ->and($template->last_used_at)->not->toBeNull();
});

test('recently used orders correctly', function () {
    TemplateFavorite::create([
        'repo_url' => 'owner/older',
        'display_name' => 'older',
        'last_used_at' => now()->subDay(),
    ]);

    TemplateFavorite::create([
        'repo_url' => 'owner/newer',
        'display_name' => 'newer',
        'last_used_at' => now(),
    ]);

    $recent = TemplateFavorite::recentlyUsed(2);

    expect($recent->first()->display_name)->toBe('newer');
});

test('most used orders correctly', function () {
    TemplateFavorite::create([
        'repo_url' => 'owner/less-used',
        'display_name' => 'less-used',
        'usage_count' => 5,
    ]);

    TemplateFavorite::create([
        'repo_url' => 'owner/more-used',
        'display_name' => 'more-used',
        'usage_count' => 10,
    ]);

    $popular = TemplateFavorite::mostUsed(2);

    expect($popular->first()->display_name)->toBe('more-used');
});
