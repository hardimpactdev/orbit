<?php

declare(strict_types=1);

namespace App\Commands\Process;

final readonly class ProcessBindValidationFailure
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public string $field,
        public string $message,
        public array $meta = [],
    ) {}
}

/**
 * @mago-expect lint:cyclomatic-complexity
 */
final readonly class ProcessBindOption
{
    public const array ALLOWED = ['wireguard', 'loopback'];

    /**
     * @param  mixed  $raw
     * @return list<string>
     */
    public static function fromOption(mixed $raw): array
    {
        if (! is_array($raw)) {
            $raw = $raw === null ? [] : [$raw];
        }

        $binds = [];

        foreach ($raw as $value) {
            $binds[] = is_string($value) ? trim($value) : '';
        }

        return $binds;
    }

    /**
     * @param  list<string>  $binds
     * @return list<string>
     */
    public static function normalize(array $binds): array
    {
        $ordered = [];

        foreach (self::ALLOWED as $selector) {
            if (in_array($selector, $binds, strict: true)) {
                $ordered[] = $selector;
            }
        }

        return $ordered;
    }

    /**
     * @param  list<string>  $binds
     */
    public static function validate(
        array $binds,
        ?string $node,
        ?string $service = null,
        ?string $runtime = null,
        bool $requireService = true,
    ): ?ProcessBindValidationFailure {
        if ($binds === []) {
            return null;
        }

        if ($node === null || $requireService && $service === null) {
            return new ProcessBindValidationFailure(
                'bind',
                'Publish binds are only supported for node-owned Docker managed services.',
                [
                    'reason' => 'process_bind_requires_node_docker_service',
                    'allowed' => self::ALLOWED,
                ],
            );
        }

        if ($runtime === 'docker-swarm') {
            return new ProcessBindValidationFailure(
                'bind',
                'Publish binds are only supported for Docker managed services.',
                [
                    'reason' => 'process_bind_requires_docker_runtime',
                    'allowed' => self::ALLOWED,
                ],
            );
        }

        if ($runtime !== null && $runtime !== 'docker') {
            return new ProcessBindValidationFailure(
                'bind',
                'Publish binds are only supported for node-owned Docker managed services.',
                [
                    'reason' => 'process_bind_requires_node_docker_service',
                    'allowed' => self::ALLOWED,
                ],
            );
        }

        foreach ($binds as $bind) {
            if ($bind === '') {
                return new ProcessBindValidationFailure(
                    'bind',
                    'Publish bind selectors cannot be empty.',
                    [
                        'reason' => 'required',
                        'allowed' => self::ALLOWED,
                    ],
                );
            }

            if (! in_array($bind, self::ALLOWED, strict: true)) {
                return new ProcessBindValidationFailure(
                    'bind',
                    "Publish bind '{$bind}' is not supported.",
                    [
                        'value' => $bind,
                        'reason' => 'unsupported_value',
                        'allowed' => self::ALLOWED,
                    ],
                );
            }
        }

        return null;
    }
}
