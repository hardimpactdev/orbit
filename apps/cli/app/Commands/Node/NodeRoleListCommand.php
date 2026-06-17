<?php

declare(strict_types=1);

namespace App\Commands\Node;

use App\Commands\Concerns\ResolvesDefaultNode;
use App\Commands\GatewayCommand;
use App\Exceptions\GatewayApiException;
use App\Exceptions\OrbitConfigStoreException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

use function Laravel\Prompts\table;

final class NodeRoleListCommand extends GatewayCommand
{
    use ResolvesDefaultNode;

    #[\Override]
    protected $name = 'node role:list';

    #[\Override]
    protected $description = 'List role assignments for a node.';

    protected function configure(): void
    {
        parent::configure();

        $this->addArgument('node', InputArgument::OPTIONAL, 'Name of the node');
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');
    }

    public function handle(): int
    {
        try {
            $node = $this->nodeArgumentOrDefault('node');
        } catch (OrbitConfigStoreException $exception) {
            return $this->renderFailure($exception->orbitCode, $exception->getMessage());
        }

        if ($node === null) {
            return $this->renderFailure('validation_failed', 'The node argument is required.', ['field' => 'node']);
        }

        $encoded = rawurlencode($node);

        try {
            $response = $this->gatewayGet("/api/nodes/{$encoded}/roles");
        } catch (GatewayApiException $exception) {
            return $this->renderFailure($exception->cliFailureCode(), $exception->getMessage());
        }

        if ($this->wantsJson()) {
            return $this->renderSuccess($response);
        }

        $roles = $this->rolesFromResponse($response);

        if ($roles === []) {
            $this->line("No role assignments found for node {$node}.");

            return self::SUCCESS;
        }

        table(
            headers: ['ROLE', 'STATUS', 'SETTINGS', 'LAST ERROR', 'CONVERGED AT'],
            rows: array_map(fn (array $role): array => [
                $this->roleString($role, 'role'),
                $this->roleString($role, 'status'),
                $this->settingsLabel($role),
                $this->roleString($role, 'last_error'),
                $this->roleString($role, 'converged_at'),
            ], $roles),
        );

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $response
     * @return list<array<string, mixed>>
     */
    private function rolesFromResponse(array $response): array
    {
        $roles = $response['success']['data']['roles'] ?? null;

        if (! is_array($roles)) {
            return [];
        }

        return array_values(array_filter($roles, is_array(...)));
    }

    /**
     * @param  array<string, mixed>  $role
     */
    private function settingsLabel(array $role): string
    {
        $settings = $role['settings'] ?? null;

        if (! is_array($settings) || $settings === []) {
            return '—';
        }

        $pairs = [];

        foreach ($settings as $key => $value) {
            $pairs[] = is_scalar($value)
                ? "{$key}={$value}"
                : "{$key}=".json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        }

        return implode(', ', $pairs);
    }

    /**
     * @param  array<string, mixed>  $role
     */
    private function roleString(array $role, string $key): string
    {
        $value = $role[$key] ?? null;

        if (is_scalar($value) && (string) $value !== '') {
            return (string) $value;
        }

        return '—';
    }
}
