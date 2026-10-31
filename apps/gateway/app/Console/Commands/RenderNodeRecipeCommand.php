<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Nodes\RoleName;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\Nodes\NodeEnrollmentRecipe;
use App\Models\Node;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Symfony\Component\Console\Output\OutputInterface;

/** Exports immutable public input; all execution belongs to native Swamp workflows. */
final class RenderNodeRecipeCommand extends Command
{
    #[\Override]
    protected $signature = 'orbit:node-recipe
        {roles?* : Orbit roles of the Node, such as app-dev or vpn; none selects a roleless operator Node}
        {--architecture=x86_64 : The Linux architecture of the recipe}
        {--json : Print the public Node metadata, bootstrap input, recipe and observer as JSON}';

    #[\Override]
    protected $description = 'Export public Node and machine recipe metadata for native bootstrap consumers.';

    public function handle(NodeEnrollmentRecipe $enrollment, AgentDelivery $delivery): int
    {
        $architecture = (string) $this->option('architecture');

        try {
            $metadata = $enrollment->metadata($this->roles(), $architecture);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if (! $this->option('json')) {
            $this->rawLine('linux '.$architecture.' orbit '.implode(' ', $metadata['node']['roles']));

            return self::SUCCESS;
        }

        $node = new Node(['platform' => 'linux', 'architecture' => $architecture]);
        $this->rawLine(json_encode($metadata + [
            'agent' => ['path' => $delivery->localFile($node), 'sha256' => $delivery->pin($node)],
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
