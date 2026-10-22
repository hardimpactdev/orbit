<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Instances;

use InvalidArgumentException;

/** The result of a successful instance.publish operation. */
final readonly class InstancePublishResult
{
    public function __construct(public string $branch, public string $commit, public ?int $pullRequestNumber, public ?string $pullRequestUrl) {}

    /** @param array<string, mixed> $data */
    public static function fromGatewayData(array $data): self
    {
        $number = $data['pull_request_number'] ?? null;
        $url = $data['pull_request_url'] ?? null;
        if (! is_string($data['branch'] ?? null) || ! is_string($data['commit'] ?? null)
            || ($number !== null && ! is_int($number))
            || ($url !== null && ! is_string($url))) {
            throw new InvalidArgumentException('Gateway response contains an invalid Instance publish result.');
        }

        return new self($data['branch'], $data['commit'], $number, $url);
    }
}
