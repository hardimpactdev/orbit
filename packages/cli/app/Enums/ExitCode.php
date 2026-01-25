<?php

namespace App\Enums;

enum ExitCode: int
{
    case Success = 0;
    case GeneralError = 1;
    case InvalidArguments = 2;
    case DockerNotRunning = 3;
    case ServiceFailed = 4;
    case ConfigurationError = 5;

    public function message(): string
    {
        return match ($this) {
            self::Success => 'Success',
            self::GeneralError => 'General error',
            self::InvalidArguments => 'Invalid arguments',
            self::DockerNotRunning => 'Docker is not running',
            self::ServiceFailed => 'Service failed to start',
            self::ConfigurationError => 'Configuration error',
        };
    }
}
