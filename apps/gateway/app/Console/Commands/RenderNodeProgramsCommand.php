<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Nodes\RoleName;
use App\Infrastructure\Nodes\NodeEnrollmentPrograms;
use App\Infrastructure\Ssh\RemoteCommand;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Renders the idempotent programs that enrollment runs on a Linux Node with these roles, without SSH, a database row,
 * or network. The E2E topology harness runs them in a builder VM to bake a role image.
 */
final class RenderNodeProgramsCommand extends Command
{
    #[\Override]
    protected $signature = 'orbit:node-programs
        {roles?* : Orbit roles of the Node, such as app-dev or vpn; none renders a roleless operator Node}
        {--json : Print {"programs":[{"name","arguments","input","fingerprint"}]} instead of one name per line}';

    #[\Override]
    protected $description = 'Render the enrollment programs of a Linux Node with these roles, in enrollment order.';

    public function handle(NodeEnrollmentPrograms $enrollment): int
    {
        try {
            $programs = $enrollment->render($this->roles());
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        foreach ($programs as $name => $program) {
            if ($program->protectedInput !== null) {
                $this->error("Program [{$name}] has protected input, so no image can run it.");

                return self::FAILURE;
            }
        }

        if (! $this->option('json')) {
            foreach (array_keys($programs) as $name) {
                $this->rawLine($name);
            }

            return self::SUCCESS;
        }

        $this->rawLine(json_encode([
            'programs' => array_map(
                static fn (string $name, RemoteCommand $program): array => [
                    'name' => $name,
                    'arguments' => $program->arguments,
                    'input' => $program->input,
                    // The image manifest fingerprint that enrollment compares, so a builder never computes its own.
                    'fingerprint' => $program->fingerprint(),
                ],
                array_keys($programs),
                array_values($programs),
            ),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }

    /** @return list<RoleName> */
    private function roles(): array
    {
        $roles = [];
        foreach ((array) $this->argument('roles') as $value) {
            $role = RoleName::tryFrom((string) $value);
            if ($role === null) {
                throw new InvalidArgumentException("Unknown role [{$value}].");
            }
            $roles[] = $role;
        }

        return $roles;
    }

    private function rawLine(string $line): void
    {
        $this->output->getOutput()->write($line, true, OutputInterface::OUTPUT_RAW);
    }
}
