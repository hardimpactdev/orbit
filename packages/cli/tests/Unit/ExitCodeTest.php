<?php

use App\Enums\ExitCode;

it('has correct exit code values', function () {
    expect(ExitCode::Success->value)->toBe(0);
    expect(ExitCode::GeneralError->value)->toBe(1);
    expect(ExitCode::InvalidArguments->value)->toBe(2);
    expect(ExitCode::DockerNotRunning->value)->toBe(3);
    expect(ExitCode::ServiceFailed->value)->toBe(4);
    expect(ExitCode::ConfigurationError->value)->toBe(5);
});

it('returns correct messages', function () {
    expect(ExitCode::Success->message())->toBe('Success');
    expect(ExitCode::GeneralError->message())->toBe('General error');
    expect(ExitCode::InvalidArguments->message())->toBe('Invalid arguments');
    expect(ExitCode::DockerNotRunning->message())->toBe('Docker is not running');
    expect(ExitCode::ServiceFailed->message())->toBe('Service failed to start');
    expect(ExitCode::ConfigurationError->message())->toBe('Configuration error');
});
