<?php

describe('Provisioning Page', function () {
    test('can view provisioning form', function () {
        $this->visit('/provision')
            ->assertSee('Provision')
            ->assertSee('Environment Name')
            ->assertSee('Host IP Address');
    });

    test('can interact with form fields', function () {
        $this->visit('/provision')
            ->type('#name', 'Test Server')
            ->type('#host', '192.168.1.100')
            ->type('#user', 'root')
            ->assertSee('Check Server');
    });

    test('shows SSH user field', function () {
        $this->visit('/provision')
            ->assertSee('SSH User');
    });
});
