<?php

declare(strict_types=1);

namespace App\Support\Csp\Presets;

use Spatie\Csp\Directive;
use Spatie\Csp\Keyword;
use Spatie\Csp\Policy;
use Spatie\Csp\Preset;

class Development implements Preset
{
    /**
     * Loopback origins the Vite dev server may bind to. Which one it picks
     * depends on the host and on whether it found a TLS certificate, so both
     * are allowed rather than guessed at. IPv6 literals are deliberately
     * absent: CSP host-source syntax has no room for them, and browsers drop
     * the whole source with a console warning.
     */
    private const LOOPBACK_HOSTS = ['localhost', '127.0.0.1'];

    public function configure(Policy $policy): void
    {
        if (app()->environment('production')) {
            return;
        }

        $appUrl = config('app.url');
        $appUrl = is_string($appUrl) && $appUrl !== '' ? $appUrl : 'http://localhost';

        $appHost = parse_url($appUrl, PHP_URL_HOST);
        if (! is_string($appHost) || $appHost === '') {
            $appHost = 'localhost';
        }

        $appOrigins = ['https://'.$appHost.':*', 'http://'.$appHost.':*'];
        $devOrigins = [...$appOrigins, ...$this->loopbackOrigins('http'), ...$this->loopbackOrigins('https')];

        $policy
            ->add(Directive::CONNECT, [
                ...$devOrigins,
                'wss://'.$appHost.':*',
                'ws://'.$appHost.':*',
                ...$this->loopbackOrigins('ws'),
                ...$this->loopbackOrigins('wss'),
            ])
            ->add(Directive::STYLE, [...$devOrigins, Keyword::UNSAFE_INLINE])
            ->add(Directive::FRAME, $appOrigins)
            ->add(Directive::SCRIPT, [...$devOrigins, Keyword::UNSAFE_INLINE])
            ->add(Directive::FONT, $devOrigins)
            ->add(Directive::IMG, [...$devOrigins, 'data:']);
    }

    /**
     * @return list<string>
     */
    private function loopbackOrigins(string $scheme): array
    {
        return array_map(fn (string $host): string => $scheme.'://'.$host.':*', self::LOOPBACK_HOSTS);
    }
}
