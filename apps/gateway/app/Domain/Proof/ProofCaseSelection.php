<?php

declare(strict_types=1);

namespace App\Domain\Proof;

use App\Domain\Shared\ResourceOperationException;
use App\Support\ValidatedData;

/** Pure definition comparison; bin/ci-build-plan has the Python twin, tested with the same fixtures. */
final readonly class ProofCaseSelection
{
    /**
     * @param  array<string,mixed>|null  $before
     * @param  array<string,mixed>  $after
     * @param  array<string,mixed>  $beforeBatches
     * @param  array<string,mixed>  $afterBatches
     * @return array<string,string> expanded case id => selection reason
     */
    public static function select(?array $before, array $after, bool $codeChanged = false, array $beforeBatches = [], array $afterBatches = []): array
    {
        $invalid = static fn (string $why): ResourceOperationException => new ResourceOperationException('proof.selection_invalid', $why, 409);
        $normalize = static function (mixed $value) use (&$normalize): mixed {
            if (! is_array($value)) {
                return $value;
            }
            if (! array_is_list($value)) {
                ksort($value);
            }

            return array_map($normalize, $value);
        };
        $same = static fn (mixed $a, mixed $b): bool => $normalize($a) === $normalize($b);
        $entries = static function (array $spec) use ($invalid): array {
            $cases = $spec['cases'] ?? null;
            if (! is_array($cases) || ! array_is_list($cases) || $cases === []) {
                throw $invalid('Selected command has no valid cases.');
            }
            $found = [];
            foreach ($cases as $case) {
                if (! is_array($case) || ! is_string($case['id'] ?? null) || preg_match('/\A[a-zA-Z0-9_-]+\z/D', $case['id']) !== 1 || ! is_string($case['batch'] ?? null) || isset($found[$case['id']])) {
                    throw $invalid('Invalid or duplicate case id.');
                }
                $variants = $case['variants'] ?? null;
                if ($variants !== null) {
                    if (! is_array($variants) || ! array_is_list($variants) || $variants === []) {
                        throw $invalid('Invalid variants.');
                    }
                    $names = [];
                    foreach ($variants as $variant) {
                        if (! is_array($variant) || ! is_string($variant['name'] ?? null) || preg_match('/\A[a-zA-Z0-9_-]+\z/D', $variant['name']) !== 1 || isset($names[$variant['name']])) {
                            throw $invalid('Invalid or duplicate variant.');
                        }
                        $names[$variant['name']] = true;
                    }
                }
                $found[$case['id']] = $case;
            }

            return $found;
        };
        $dependencies = static function (array $case, array $spec) use ($invalid): array {
            $used = ['fixtures' => [], 'vars' => []];
            $fixtures = $spec['fixtures'] ?? [];
            $variables = $spec['vars'] ?? [];
            $resolve = static function (mixed $value, array $context) use (&$resolve): mixed {
                if (is_string($value)) {
                    if (preg_match('/\A\$\{([^}]+)\}\z/D', $value, $match) === 1 && array_key_exists($match[1], $context)) {
                        return $context[$match[1]];
                    }

                    return preg_replace_callback('/\$\{([^}]+)\}/', static fn (array $match): string => (string) ($context[$match[1]] ?? $match[0]), $value);
                }

                return is_array($value) ? array_map(static fn (mixed $item): mixed => $resolve($item, $context), $value) : $value;
            };
            $walk = static function (mixed $value, array $context, array $overrides, array $visiting = []) use (&$walk, &$used, $fixtures, $variables, $invalid, $resolve): void {
                $value = $resolve($value, $context);
                if (is_string($value) && str_starts_with($value, '@')) {
                    $name = substr($value, 1);
                    if (! array_key_exists($name, $overrides) && array_key_exists($name, $variables)) {
                        if (in_array('@'.$name, $visiting, true)) {
                            throw $invalid('Recursive var.');
                        }
                        $used['vars'][$name] = true;
                        $walk($variables[$name], $context, $overrides, [...$visiting, '@'.$name]);
                    }
                } elseif (is_array($value)) {
                    if (array_key_exists('use', $value)) {
                        $args = is_string($value['use']) ? ['fixture' => $value['use']] : $value['use'];
                        if (! is_array($args)) {
                            throw $invalid('Invalid fixture use.');
                        }
                        $name = $args['fixture'] ?? null;
                        if (is_string($name)) {
                            $name = preg_replace_callback('/\$\{([^}]+)\}/', static fn (array $match): string => (string) ($context[$match[1]] ?? $match[0]), $name);
                        }
                        if (! is_string($name) || ! isset($fixtures[$name]) || in_array($name, $visiting, true)) {
                            throw $invalid('Missing or recursive fixture.');
                        }
                        $used['fixtures'][$name] = true;
                        $fixture = $fixtures[$name];
                        $walk($fixture['steps'] ?? [], [...$context, ...($fixture['params'] ?? []), ...array_diff_key($args, ['fixture' => true])], $overrides, [...$visiting, $name]);
                    }
                    foreach ($value as $item) {
                        $walk($item, $context, $overrides, $visiting);
                    }
                }
            };
            foreach ($case['variants'] ?? [[]] as $variant) {
                $overrides = array_diff_key($variant, ['name' => true]);
                $walk($case, $overrides, $overrides);
            }

            return $used;
        };
        $current = $entries($after);
        $previous = $before === null ? [] : $entries($before);
        $fileChanged = array_any(['target', 'sources', 'baseline', 'spread', 'parallel'], static fn (string $key): bool => ! $same($before[$key] ?? null, $after[$key] ?? null));
        $selected = [];
        foreach ($current as $name => $case) {
            $reason = match (true) {
                $codeChanged => 'command code changed',
                ! isset($previous[$name]) => 'added',
                ! $same($previous[$name], $case) => 'changed',
                default => null,
            };
            if ($reason === null) {
                $oldUses = $dependencies($previous[$name], $before);
                $uses = $dependencies($case, $after);
                foreach (['fixtures' => 'uses changed fixture', 'vars' => 'uses changed var'] as $kind => $label) {
                    if (array_any(array_keys($oldUses[$kind] + $uses[$kind]), static fn (string $key): bool => ! $same(ValidatedData::object($before[$kind] ?? [])[$key] ?? null, ValidatedData::object($after[$kind] ?? [])[$key] ?? null))) {
                        $reason = $label;
                        break;
                    }
                }
                $batch = ValidatedData::string($case['batch']);
                if ($reason === null && ! $same(ValidatedData::object($beforeBatches[$batch] ?? [])['setup'] ?? null, ValidatedData::object($afterBatches[$batch] ?? [])['setup'] ?? null)) {
                    $reason = 'batch setup changed';
                }
                if ($reason === null && $fileChanged) {
                    $reason = 'file settings changed';
                }
            }
            if ($reason !== null) {
                $variants = $case['variants'] ?? [null];
                if (! is_array($variants)) {
                    throw $invalid('Invalid variants.');
                }
                foreach ($variants as $variant) {
                    $selected[$name.($variant === null ? '' : '['.ValidatedData::string(ValidatedData::object($variant)['name']).']')] = $reason;
                }
            }
        }

        return $selected;
    }
}
