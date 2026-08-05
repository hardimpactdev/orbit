<?php

declare(strict_types=1);

namespace App\Services\ApplicationLogs;

/**
 * Resolves instance:log target tokens into route selector + requested-target label.
 */
final readonly class ApplicationLogInstanceTargetResolver
{
    public function __construct(
        private ApplicationLogInstanceSelector $selectors = new ApplicationLogInstanceSelector,
        private ApplicationLogUrlParser $urls = new ApplicationLogUrlParser,
        private ApplicationLogProxyRouteMatcher $routes = new ApplicationLogProxyRouteMatcher,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $routeList
     * @return array{ok: true, selector: string, requested_target: string}|array{ok: false, field: string, message: string, meta: array<string, mixed>}
     */
    public function resolve(string $target, array $routeList): array
    {
        $canonical = $this->selectors->parse($target);

        if ($canonical['ok'] === true) {
            return [
                'ok' => true,
                'selector' => $canonical['selector'],
                'requested_target' => $canonical['selector'],
            ];
        }

        $host = $this->urls->parse($target);

        if ($host['ok'] === false) {
            return [
                'ok' => false,
                'field' => $host['field'],
                'message' => $host['message'],
                'meta' => [],
            ];
        }

        $matched = $this->routes->match($host['host'], $routeList);

        if ($matched['ok'] === false) {
            return [
                'ok' => false,
                'field' => $matched['field'],
                'message' => $matched['message'],
                'meta' => $matched['meta'],
            ];
        }

        if ($matched['type'] === 'workspace') {
            return [
                'ok' => false,
                'field' => 'target',
                'message' => 'The host resolves to a workspace. Use workspace:log or app:log.',
                'meta' => ['host' => $host['host'], 'reason' => 'wrong_target_type'],
            ];
        }

        return [
            'ok' => true,
            'selector' => $matched['selector'],
            'requested_target' => $host['host'],
        ];
    }
}
