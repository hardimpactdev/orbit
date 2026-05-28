<?php

declare(strict_types=1);

namespace App\Services\Node;

use InvalidArgumentException;

class NodeWriteInputException extends InvalidArgumentException
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public readonly string $orbitCode,
        string $message,
        public readonly array $meta = [],
    ) {
        parent::__construct($message);
    }
}
