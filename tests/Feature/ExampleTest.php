<?php

test('homepage redirects to create when no environments exist', function () {
    if (!config('orbit.multi_environment')) {
        $this->get('/')->assertStatus(500); // Middleware fails because no local env
        return;
    }

    $response = $this->get('/');

    $response->assertRedirect('/environments/create');
});

test('homepage redirects to default environment when one exists', function () {
    $environment = createEnvironment(['is_local' => true, 'host' => 'localhost']);

    $response = $this->get('/');

    if (config('orbit.multi_environment')) {
        $response->assertRedirect("/environments/{$environment->id}");
    } else {
        $response->assertRedirect("/sites");
    }
});
