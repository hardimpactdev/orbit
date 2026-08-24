<?php

declare(strict_types=1);

describe('appearance defaults', function (): void {
    it('renders new visitors in system mode by default', function (): void {
        $response = $this->get('/');

        $response
            ->assertOk()
            ->assertSee("var appearance = 'system';", escape: false);
    });

    it('preserves system appearance cookie setting', function (): void {
        $response = $this->withUnencryptedCookie('appearance', 'system')->get('/');

        $response
            ->assertOk()
            ->assertSee("var appearance = 'system';", escape: false);
    });

    it('honors explicit dark or light appearance cookies', function (): void {
        $darkResponse = $this->withUnencryptedCookie('appearance', 'dark')->get('/');
        $darkResponse
            ->assertOk()
            ->assertSee("var appearance = 'dark';", escape: false)
            ->assertSee('<html lang="en" class="dark">', escape: false);

        $lightResponse = $this->withUnencryptedCookie('appearance', 'light')->get('/');
        $lightResponse
            ->assertOk()
            ->assertSee("var appearance = 'light';", escape: false)
            ->assertDontSee('<html lang="en" class="dark">', escape: false);
    });

    it('initializes the client preference as system', function (): void {
        $hook = file_get_contents(resource_path('js/hooks/use-appearance.tsx'));

        expect($hook)
            ->toContain('let currentAppearance: Appearance = "system";')
            ->toContain('return "system";');
    });
});
