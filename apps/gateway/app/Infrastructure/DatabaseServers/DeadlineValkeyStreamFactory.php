<?php

declare(strict_types=1);

namespace App\Infrastructure\DatabaseServers;

use App\Infrastructure\Processes\CommandDeadline;
use Predis\Connection\Parameters;
use Predis\Connection\ParametersInterface;
use Predis\Connection\Resource\StreamFactory;
use Psr\Http\Message\StreamInterface;

final class DeadlineValkeyStreamFactory extends StreamFactory
{
    public function __construct(private readonly CommandDeadline $deadline) {}

    public function createStream(ParametersInterface $parameters): StreamInterface
    {
        $parameters = new Parameters([
            ...$parameters->toArray(),
            'timeout' => $this->deadline->cap((float) $parameters->timeout),
        ]);
        $stream = parent::createStream($parameters);
        $socket = $stream->detach();
        assert(is_resource($socket));

        return new DeadlineValkeyStream($socket, $this->deadline, (float) $parameters->read_write_timeout);
    }
}
