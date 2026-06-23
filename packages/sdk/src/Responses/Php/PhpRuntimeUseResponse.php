<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Responses\Php;

final readonly class PhpRuntimeUseResponse
{
    /**
     * @param  array<string, mixed>  $php
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public array $php,
        public array $result,
        public array $meta = [],
    ) {}
}
