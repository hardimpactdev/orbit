<?php

declare(strict_types=1);

namespace App\Http\Gateway\Responses\Nodes;

final readonly class NodeUpdateResponse
{
    /**
     * @param  list<string>  $changed
     */
    public function __construct(
        public string $name,
        public array $changed,
    ) {}
}
