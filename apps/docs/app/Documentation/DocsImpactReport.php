<?php

declare(strict_types=1);

namespace App\Documentation;

use InvalidArgumentException;

/** Validate the scanner's existing payload without rebuilding, filtering, or shortening it. */
final class DocsImpactReport
{
    /** @param array<string, mixed> $report
     * @param  list<string>  $paths
     */
    public static function status(array $report, string $base, array $paths): string
    {
        self::keys($report, ['base', 'paths', 'impacted_pages', 'surfaces', 'surfaces_handled_by_generator', 'errors', 'verdict']);
        sort($paths, SORT_STRING);
        if ($report['base'] !== $base || $report['paths'] !== array_values(array_unique($paths))
            || ! in_array($report['verdict'], ['no_docs_change', 'docs_required'], true)) {
            throw new InvalidArgumentException('Scanner identity, path union, or verdict differs.');
        }
        foreach (['paths', 'impacted_pages', 'surfaces', 'surfaces_handled_by_generator', 'errors'] as $list) {
            if (! is_array($report[$list]) || ! array_is_list($report[$list])) {
                throw new InvalidArgumentException('Scanner field is not a list.');
            }
        }
        foreach ([...$report['paths'], ...$report['errors']] as $text) {
            self::text($text);
        }
        foreach ($report['impacted_pages'] as $page) {
            self::keys($page, ['page', 'reasons']);
            self::text($page['page']);
            if (! is_array($page['reasons']) || ! array_is_list($page['reasons']) || $page['reasons'] === []) {
                throw new InvalidArgumentException('Missing page reasons.');
            }
            foreach ($page['reasons'] as $reason) {
                self::text($reason);
            }
        }
        $blocked = $report['errors'] !== [];
        foreach ($report['surfaces'] as $surface) {
            self::keys($surface, ['path', 'kind', 'owner', 'reason', 'generator_status']);
            foreach (['path', 'kind', 'owner', 'reason'] as $key) {
                self::text($surface[$key]);
            }
            if ($surface['generator_status'] !== null && ! in_array($surface['generator_status'], ['passed', 'failed', 'stale', 'missing'], true)) {
                throw new InvalidArgumentException('Invalid surface generator status.');
            }
            $blocked = $blocked || $surface['owner'] === 'unowned' || ($surface['generator_status'] !== null && $surface['generator_status'] !== 'passed');
        }
        foreach ($report['surfaces_handled_by_generator'] as $generator) {
            self::keys($generator, ['generator', 'paths', 'status', 'current']);
            self::text($generator['generator']);
            if (! is_array($generator['paths']) || ! array_is_list($generator['paths']) || ! is_bool($generator['current'])
                || ! in_array($generator['status'], ['passed', 'failed', 'stale', 'missing'], true)
                || $generator['current'] !== ($generator['status'] === 'passed')) {
                throw new InvalidArgumentException('Contradictory generator status.');
            }
            foreach ($generator['paths'] as $path) {
                self::text($path);
            }
            $blocked = $blocked || $generator['status'] !== 'passed';
        }
        if ($report['verdict'] === 'no_docs_change' && ($blocked || $report['impacted_pages'] !== [])) {
            return 'blocked';
        }
        if ($blocked) {
            return 'blocked';
        }

        return $report['verdict'] === 'no_docs_change' ? 'ready' : 'action_required';
    }

    /** @param list<string> $keys */
    private static function keys(mixed $value, array $keys): void
    {
        if (! is_array($value)) {
            throw new InvalidArgumentException('Scanner object is invalid.');
        }
        $actual = array_keys($value);
        sort($actual);
        sort($keys);
        if ($actual !== $keys) {
            throw new InvalidArgumentException('Scanner object fields differ.');
        }
    }

    private static function text(mixed $value): void
    {
        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException('Scanner text field is invalid.');
        }
    }
}
