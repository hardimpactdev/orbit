<?php

declare(strict_types=1);

namespace App\Data\Instances;

use Spatie\LaravelData\Data;

final class InstancePublishInput extends Data
{
    /** @param array{base: string, title: string, body: string}|null $pull_request */
    public function __construct(public string $commit, public string $branch, public ?array $pull_request = null) {}

    /** @return array{commit: string, branch: string, pull_request?: array{base: string, title: string, body: string}} */
    public function payload(): array
    {
        return ['commit' => $this->commit, 'branch' => $this->branch, ...($this->pull_request === null ? [] : ['pull_request' => $this->pull_request])];
    }
}
