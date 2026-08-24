<?php

declare(strict_types=1);

namespace App\Support\Csp;

use Closure;
use Illuminate\Http\Request;
use Spatie\Csp\Policy;
use Spatie\Csp\Preset;
use Symfony\Component\HttpFoundation\Response;

class AddCspHeaders
{
    /** @param class-string<Preset> $customPreset */
    public function handle(
        Request $request,
        Closure $next,
        $customPreset = null
    ): Response {
        $response = $next($request);

        if (! config('csp.enabled')) {
            return $response;
        }

        // Skip CSP middleware when Laravel is rendering an exception
        if ($response->isServerError()) {
            return $response;
        }

        // Ensure custom CSP middleware registered later in the stack gets precedence
        if ($this->hasCspHeader($response)) {
            return $response;
        }

        if ($customPreset) {
            $policy = Policy::create([$customPreset]);

            $response->headers->set('Content-Security-Policy', $policy->getContents());

            return $response;
        }

        /** @var array<class-string<Preset>> $presets */
        $presets = config('csp.presets');
        /** @var array<string, string|array<string>> $directives */
        $directives = config('csp.directives');
        /** @var string|null $reportUri */
        $reportUri = config('csp.report_uri');

        $policy = Policy::create(
            presets: $presets,
            directives: $directives,
            reportUri: $reportUri,
        );

        if (! $policy->isEmpty()) {
            $response->headers->set('Content-Security-Policy', $policy->getContents());
        }

        /** @var array<class-string<Preset>> $reportOnlyPresets */
        $reportOnlyPresets = config('csp.report_only_presets');
        /** @var array<string, string|array<string>> $reportOnlyDirectives */
        $reportOnlyDirectives = config('csp.report_only_directives');
        /** @var string|null $reportUri */
        $reportUri = config('csp.report_uri');

        $reportOnlyPolicy = Policy::create(
            presets: $reportOnlyPresets,
            directives: $reportOnlyDirectives,
            reportUri: $reportUri,
        );

        if (! $reportOnlyPolicy->isEmpty()) {
            $response->headers->set('Content-Security-Policy-Report-Only', $reportOnlyPolicy->getContents());
        }

        return $response;
    }

    public function hasCspHeader(mixed $response): bool
    {
        if (! $response instanceof Response) {
            return false;
        }

        return $response->headers->has('Content-Security-Policy')
            || $response->headers->has('Content-Security-Policy-Report-Only');
    }
}
