<?php

describe('Dashboard', function () {
    test('redirects to environment create when no environments exist', function () {
        $this->visit('/')
            ->wait(1)
            ->assertPathIs('/environments/create');
    });
});
