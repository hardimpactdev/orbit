<?php

test('homepage redirects to create when no environments exist', function () {
    $response = $this->get('/');

    $response->assertRedirect('/environments/create');
});

test('homepage redirects to default environment when one exists', function () {
    $environment = createEnvironment(['is_local' => true, 'host' => 'localhost']);

    $response = $this->get('/');

    $response->assertRedirect("/environments/{$environment->id}");
});
