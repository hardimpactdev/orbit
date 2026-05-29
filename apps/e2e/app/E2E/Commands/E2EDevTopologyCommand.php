<?php

declare(strict_types=1);

namespace App\E2E\Commands;

use App\E2E\Support\E2ETopologyKind;

final readonly class E2EDevTopologyCommand
{
    public function __construct(
        private string $repoRoot,
    ) {}

    /**
     * @param  list<string>  $argv
     */
    public function run(array $argv): int
    {
        $options = $this->parse($argv);

        if ($options['help']) {
            $this->renderHelp();

            return 0;
        }

        $kind = E2ETopologyKind::tryFromInput($options['kind']);

        if ($kind === null) {
            return $this->renderError(
                'validation_failed',
                "Unsupported E2E topology kind [{$options['kind']}].",
                $options['json'],
            );
        }

        if (! in_array($options['provider'], ['docker', 'incus'], true)) {
            return $this->renderError(
                'validation_failed',
                "Unsupported E2E topology provider [{$options['provider']}].",
                $options['json'],
            );
        }

        if (! $options['dry_run']) {
            return $this->renderError(
                'provider_acquisition_unavailable',
                'Retained topology acquisition without --dry-run requires the topology provider extraction in #552.',
                $options['json'],
            );
        }

        $checkoutRoles = $options['checkout_roles'] === []
            ? $this->defaultCheckoutRoles($kind)
            : $options['checkout_roles'];

        $payload = [
            'id' => 'dry-run',
            'dry_run' => true,
            'provider' => $options['provider'],
            'host' => 'dry-run',
            'kind' => $kind->value,
            'checkout_roles' => $checkoutRoles,
            'shell_command' => $this->shellCommand($kind, $options['provider'], $checkoutRoles),
            'release_command' => 'composer e2e:dev-topology:release -- dry-run',
        ];

        if ($options['json']) {
            $this->writeJson(['success' => ['dev_topology' => $payload]]);

            return 0;
        }

        $this->line('Retained topology dry run');
        $this->line("Provider: {$payload['provider']}");
        $this->line("Kind: {$payload['kind']}");
        $this->line('Checkout roles: '.implode(', ', $checkoutRoles));
        $this->line("Shell: {$payload['shell_command']}");
        $this->line("Release: {$payload['release_command']}");
        $this->line('Source-checkout E2E remains the normal feature loop; retained topologies are manual diagnosis only.');

        return 0;
    }

    /**
     * @param  list<string>  $argv
     * @return array{dry_run: bool, json: bool, help: bool, kind: string, provider: string, checkout_roles: list<string>}
     */
    private function parse(array $argv): array
    {
        $options = [
            'dry_run' => false,
            'json' => false,
            'help' => false,
            'kind' => E2ETopologyKind::OperatorGatewayAppdev->value,
            'provider' => 'docker',
            'checkout_roles' => [],
        ];

        $arguments = array_slice($argv, 1);

        for ($index = 0; $index < count($arguments); $index++) {
            $argument = $arguments[$index];

            if ($argument === '--dry-run') {
                $options['dry_run'] = true;

                continue;
            }

            if ($argument === '--json') {
                $options['json'] = true;

                continue;
            }

            if ($argument === '--help' || $argument === '-h') {
                $options['help'] = true;

                continue;
            }

            if ($argument === '--kind') {
                $options['kind'] = $arguments[++$index] ?? '';

                continue;
            }

            if (str_starts_with($argument, '--kind=')) {
                $options['kind'] = substr($argument, strlen('--kind='));

                continue;
            }

            if ($argument === '--provider') {
                $options['provider'] = $arguments[++$index] ?? '';

                continue;
            }

            if (str_starts_with($argument, '--provider=')) {
                $options['provider'] = substr($argument, strlen('--provider='));

                continue;
            }

            if ($argument === '--checkout-role') {
                $this->appendCheckoutRoles($options['checkout_roles'], $arguments[++$index] ?? '');

                continue;
            }

            if (str_starts_with($argument, '--checkout-role=')) {
                $this->appendCheckoutRoles($options['checkout_roles'], substr($argument, strlen('--checkout-role=')));

                continue;
            }

            if ($argument === '--checkout-roles') {
                $this->appendCheckoutRoles($options['checkout_roles'], $arguments[++$index] ?? '');

                continue;
            }

            if (str_starts_with($argument, '--checkout-roles=')) {
                $this->appendCheckoutRoles($options['checkout_roles'], substr($argument, strlen('--checkout-roles=')));
            }
        }

        return $options;
    }

    /**
     * @param  list<string>  $roles
     */
    private function appendCheckoutRoles(array &$roles, string $value): void
    {
        foreach (explode(',', $value) as $role) {
            $role = trim($role);

            if ($role === '') {
                continue;
            }

            $roles[] = $role;
        }

        $roles = array_values(array_unique($roles));
    }

    /**
     * @return list<string>
     */
    private function defaultCheckoutRoles(E2ETopologyKind $kind): array
    {
        $roles = ['operator'];

        if (str_contains($kind->value, 'gateway')) {
            $roles[] = 'gateway';
        }

        if (str_contains($kind->value, 'app-dev')) {
            $roles[] = 'app-dev';
        }

        if (str_contains($kind->value, 'app-prod')) {
            $roles[] = 'app-prod';
        }

        if (str_contains($kind->value, 'agent')) {
            $roles[] = 'agent';
        }

        if (str_contains($kind->value, 'websocket')) {
            $roles[] = 'websocket';
        }

        return $roles;
    }

    /**
     * @param  list<string>  $checkoutRoles
     */
    private function shellCommand(E2ETopologyKind $kind, string $provider, array $checkoutRoles): string
    {
        $command = "composer e2e:dev-topology -- --kind={$kind->value} --provider={$provider}";

        if ($checkoutRoles !== $this->defaultCheckoutRoles($kind)) {
            $command .= ' --checkout-roles='.implode(',', $checkoutRoles);
        }

        return $command;
    }

    private function renderHelp(): void
    {
        $this->line('Usage: e2e:dev-topology [--dry-run] [--json] [--kind=KIND] [--provider=docker|incus]');
        $this->line('Retained prepared topologies are manual diagnosis tools owned by apps/e2e.');
        $this->line('Source-checkout E2E remains the normal feature loop; binary acceptance is a later release-candidate lane.');
        $this->line("Repository: {$this->repoRoot}");
    }

    private function renderError(string $code, string $message, bool $json): int
    {
        if ($json) {
            $this->writeJson([
                'error' => [
                    'code' => $code,
                    'message' => $message,
                ],
            ]);

            return 1;
        }

        fwrite(STDERR, $message.PHP_EOL);

        return 1;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function writeJson(array $payload): void
    {
        $this->line(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function line(string $line): void
    {
        fwrite(STDOUT, $line.PHP_EOL);
    }
}
