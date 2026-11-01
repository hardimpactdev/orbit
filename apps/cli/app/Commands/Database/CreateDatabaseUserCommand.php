<?php

declare(strict_types=1);

namespace App\Commands\Database;

use App\Commands\Concerns\FollowsOperations;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\StandardInputReader;
use Laravel\Prompts\PasswordPrompt;
use Orbit\Sdk\Requests\DatabaseConnections\CreateDatabaseUserRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;

class CreateDatabaseUserCommand extends DatabaseCommand
{
    use FollowsOperations;

    public const string USERNAME_PATTERN = '/\A[A-Za-z_][A-Za-z0-9_]{0,31}\z/D';

    #[\Override]
    protected $signature = 'database:user:create
        {slug : Connection slug of a database on a Database server}
        {--username= : Username, a 1-32 character identifier}
        {--read-only : Grant only SELECT on the database}
        {--replication : PostgreSQL LOGIN REPLICATION with read-only grants; requires wal_level=logical}
        {--idempotency-key= : Reuse a key to retry this operation start}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Add a user to a database on a Database server.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors, StandardInputReader $input): int
    {
        $slug = $this->slug();

        if ($slug === null) {
            return self::FAILURE;
        }

        $username = $this->stringOption('username');

        if ($username === null) {
            return $this->renderGatewayFailure('database.username_required', 'A database user requires --username.');
        }

        if (preg_match(self::USERNAME_PATTERN, $username) !== 1) {
            return $this->renderGatewayFailure('database.username_invalid', 'Username must be a 1-32 character identifier of letters, digits, and underscores.');
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $profile = $this->activeGatewayProfile($repository);
        if ($profile === null) {
            return self::FAILURE;
        }

        $password = $this->password($input);

        if ($password === null) {
            return $this->renderGatewayFailure('database.password_required', 'Pipe the password on standard input, or run this command in a terminal to type it.');
        }
        $operation = $this->send($connector, new CreateDatabaseUserRequest(
            slug: $slug,
            username: $username,
            password: $password,
            readOnly: $this->option('read-only') === true,
            replication: $this->option('replication') === true,
            idempotencyKey: is_string($this->option('idempotency-key')) ? $this->option('idempotency-key') : null,
        ), OperationResponse::class);

        return $operation instanceof OperationResponse
            ? $this->followOperation($connector, $profile, $operation, "Create Database user [{$slug}]", 'Database user')
            : self::FAILURE;
    }

    /** The password never travels in argv: piped standard input, or a hidden prompt on a terminal. */
    private function password(StandardInputReader $input): ?string
    {
        if (! $input->isTerminal()) {
            $password = rtrim($input->read(), "\r\n");
        } elseif ($this->consoleMode()->mayPrompt) {
            $password = $this->commandPrompts()->run(fn (): PasswordPrompt => new PasswordPrompt('Password', required: true));
        } else {
            return null;
        }

        return is_string($password) && $password !== '' ? $password : null;
    }
}
