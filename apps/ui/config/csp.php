<?php

declare(strict_types=1);

use App\Support\Csp\LaravelViteNonceGenerator;
use App\Support\Csp\Presets\Basic;
use App\Support\Csp\Presets\Development;

return [

    /*
     * Presets will determine which CSP headers will be set. A valid CSP preset is
     * any class that implements `Spatie\Csp\Preset`
     */
    'presets' => [
        Basic::class,
        Development::class,
    ],

    /**
     * Register additional global CSP directives here.
     */
    'directives' => [
        //
    ],

    /*
     * These presets which will be put in a report-only policy. This is great for testing out
     * a new policy or changes to existing CSP policy without breaking anything.
     */
    'report_only_presets' => [
        //
    ],

    /**
     * Register additional global report-only CSP directives here.
     */
    'report_only_directives' => [
        //
    ],

    /*
     * All violations against a policy will be reported to this url.
     * A great service you could use for this is https://report-uri.com/
     */
    'report_uri' => env('CSP_REPORT_URI', ''),

    /*
     * Headers will only be added if this setting is set to true.
     */
    'enabled' => env('CSP_ENABLED', true),

    /*
     * The class responsible for generating the nonces used in inline tags and headers.
     */
    'nonce_generator' => LaravelViteNonceGenerator::class,

    /*
     * In local dev, nonces are disabled because Vite injects scripts from :5173
     * that can't carry the nonce. The Development preset uses unsafe-inline instead.
     */
    'nonce_enabled' => env('CSP_NONCE_ENABLED', env('APP_ENV') !== 'local'),
];
