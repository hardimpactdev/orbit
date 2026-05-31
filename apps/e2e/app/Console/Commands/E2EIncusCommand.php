<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('e2e:incus
    {--start : Acquire a retained Incus topology}
    {--stop : Release a retained Incus topology}
    {--topology=operator_gateway_app-dev : Prepared topology kind to acquire}
    {--id= : Retained topology id to release}
    {--all : Release every recorded retained topology}
    {--checkout-roles= : Comma-separated roles to overlay the current checkout onto}
    {--dry-run : Render the acquisition plan without provisioning a topology}
    {--json : Output as JSON}')]
#[Description('Start or stop retained Incus topologies for manual diagnosis')]
class E2EIncusCommand extends Command
{
    public function handle(): int
    {
        $json = (bool) $this->option('json');
        $start = (bool) $this->option('start');
        $stop = (bool) $this->option('stop');

        if ($start === $stop) {
            return $this->renderError('validation_failed', 'Choose exactly one Incus topology action: --start or --stop.', $json);
        }

        if ($start) {
            return $this->start();
        }

        return $this->stop();
    }

    private function start(): int
    {
        $parameters = [
            '--provider' => 'incus',
            '--kind' => (string) $this->option('topology'),
            '--json' => (bool) $this->option('json'),
            '--dry-run' => (bool) $this->option('dry-run'),
        ];

        $checkoutRoles = $this->option('checkout-roles');

        if (is_string($checkoutRoles) && trim($checkoutRoles) !== '') {
            $parameters['--checkout-roles'] = $checkoutRoles;
        }

        return $this->call('e2e:dev-topology', $parameters);
    }

    private function stop(): int
    {
        $parameters = [
            '--json' => (bool) $this->option('json'),
            '--all' => (bool) $this->option('all'),
        ];

        $id = $this->option('id');

        if (is_string($id) && trim($id) !== '') {
            $parameters['id'] = $id;
        }

        return $this->call('e2e:dev-topology:release', $parameters);
    }

    private function renderError(string $code, string $message, bool $json): int
    {
        if ($json) {
            $this->line(json_encode([
                'error' => [
                    'code' => $code,
                    'message' => $message,
                ],
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }

        $this->components->error($message);

        return self::FAILURE;
    }
}
