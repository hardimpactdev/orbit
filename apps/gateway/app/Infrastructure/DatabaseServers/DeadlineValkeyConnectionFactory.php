<?php

declare(strict_types=1);

namespace App\Infrastructure\DatabaseServers;

use App\Infrastructure\Processes\CommandDeadline;
use Predis\Connection\Factory;
use Predis\Connection\ParametersInterface;
use Predis\Connection\StreamConnection;

final class DeadlineValkeyConnectionFactory extends Factory
{
    public function __construct(CommandDeadline $deadline)
    {
        $this->define('tcp', function (ParametersInterface $parameters) use ($deadline): StreamConnection {
            $connection = new StreamConnection($parameters, new DeadlineValkeyStreamFactory($deadline));
            $this->prepareConnection($connection);

            return $connection;
        });
    }
}
