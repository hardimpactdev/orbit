<?php

declare(strict_types=1);

namespace App\Librarian;

final readonly class CommandCatalogEndpointNormalizer
{
    public function endpointKey(string $method, string $uri): string
    {
        return strtoupper($method).' '.$this->normalizeApiUri($uri);
    }

    public function normalizeApiUri(string $uri): string
    {
        $uri = trim($uri);
        $uri = preg_replace(pattern: '/\{\$([A-Za-z_][A-Za-z0-9_]*)\}/', replacement: '{$1}', subject: $uri) ?? $uri;
        $uri = preg_replace(pattern: '/(?<!\{)\$([A-Za-z_][A-Za-z0-9_]*)/', replacement: '{$1}', subject: $uri) ?? $uri;

        if (! str_starts_with($uri, '/')) {
            $uri = "/{$uri}";
        }

        return $uri;
    }

    public function normalizePhpEndpointExpression(string $expression): ?string
    {
        $expression = $this->replacePhpPropertyPlaceholders($expression);
        $expression = $this->replaceLocalVariablePlaceholders($expression);

        if (preg_match('/[()?:]/', $expression) === 1) {
            return null;
        }

        $uri = str_replace(search: ["'", '"', '.', ' ', "\n", "\r", "\t"], replace: '', subject: $expression);

        if (! str_starts_with($uri, '/api/')) {
            return null;
        }

        return $this->normalizeApiUri($uri);
    }

    private function replaceLocalVariablePlaceholders(string $expression): string
    {
        return (
            preg_replace_callback(
                pattern: '/(?<!\{)\$([A-Za-z_][A-Za-z0-9_]*)/',
                callback: static fn (array $match): string => '{'.$match[1].'}',
                subject: $expression,
            ) ?? $expression
        );
    }

    private function replacePhpPropertyPlaceholders(string $expression): string
    {
        $expression =
            preg_replace_callback(
                pattern: '/rawurlencode\(\$this->([A-Za-z_][A-Za-z0-9_]*)\)/',
                callback: static fn (array $match): string => '{'.$match[1].'}',
                subject: $expression,
            ) ?? $expression;
        $expression =
            preg_replace_callback(
                pattern: '/\{\$this->([A-Za-z_][A-Za-z0-9_]*)\}/',
                callback: static fn (array $match): string => '{'.$match[1].'}',
                subject: $expression,
            ) ?? $expression;

        return (
            preg_replace_callback(
                pattern: '/\$this->([A-Za-z_][A-Za-z0-9_]*)/',
                callback: static fn (array $match): string => '{'.$match[1].'}',
                subject: $expression,
            ) ?? $expression
        );
    }
}
