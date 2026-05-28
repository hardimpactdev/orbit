<?php

declare(strict_types=1);

namespace App\Services\Profile;

final class ProfileInputResolver
{
    public function resolve(
        ?string $target,
        ?string $app,
        mixed $uriOption,
        bool $asFirstUser,
        ?string $user,
        ?string $node,
        ?string $appMarker,
        ?string $hostCwd,
    ): ProfileInput|ProfileInputFailure {
        if ($target !== null && $app !== null) {
            return new ProfileInputFailure('validation_failed', 'Use either target or --app, not both.', [
                'field' => 'target',
                'reason' => 'conflicts_with_app',
            ]);
        }

        if ($asFirstUser && $user !== null) {
            return new ProfileInputFailure('validation_failed', 'Use either --as-first-user or --user, not both.', [
                'field' => 'auth',
                'reason' => 'conflicting_auth_modes',
            ]);
        }

        $uri = $this->normalizedUri($uriOption);

        if ($uri === null) {
            return new ProfileInputFailure('validation_failed', 'Profile URI must be a non-empty path.', [
                'field' => 'uri',
                'reason' => 'invalid_path',
            ]);
        }

        $targetWasOmitted = $target === null && $app === null;
        $selector = $app ?? $target ?? $appMarker ?? $hostCwd;

        if ($selector !== null && preg_match('#^https?://#i', $selector) === 1) {
            $parsed = $this->parseUrlTarget($selector, $uri, $uriOption);

            if ($parsed === null) {
                return new ProfileInputFailure('validation_failed', "Could not parse host from URL '{$selector}'.", [
                    'field' => 'target',
                    'reason' => 'invalid_url',
                ]);
            }

            [$selector, $uri] = $parsed;
        }

        if ($selector === null) {
            return new ProfileInputFailure('validation_failed', 'Specify a target or --app.', [
                'field' => 'target',
                'reason' => 'missing_required_input',
            ]);
        }

        return new ProfileInput(
            target: $selector,
            uri: $uri,
            authMode: $this->authMode($asFirstUser, $user),
            user: $user,
            node: $node,
            targetWasOmitted: $targetWasOmitted,
        );
    }

    private function normalizedUri(mixed $value): ?string
    {
        if ($value === null || $value === false) {
            return '/';
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $uri = trim($value);

        return str_starts_with($uri, '/') ? $uri : "/{$uri}";
    }

    /**
     * @return array{string, string}|null
     */
    private function parseUrlTarget(string $target, string $currentUri, mixed $uriOption): ?array
    {
        $parts = parse_url($target);

        if (! is_array($parts) || ! is_string($parts['host'] ?? null) || $parts['host'] === '') {
            return null;
        }

        $uri = $currentUri;

        if ($uriOption === null || $uriOption === false) {
            $path = is_string($parts['path'] ?? null) && $parts['path'] !== '' ? $parts['path'] : '/';
            $query = is_string($parts['query'] ?? null) && $parts['query'] !== '' ? "?{$parts['query']}" : '';
            $uri = "{$path}{$query}";
        }

        return [$parts['host'], $uri];
    }

    private function authMode(bool $asFirstUser, ?string $user): string
    {
        if ($user !== null) {
            return 'user';
        }

        if ($asFirstUser) {
            return 'first-user';
        }

        return 'guest';
    }
}
