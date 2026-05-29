<?php

declare(strict_types=1);

namespace App\Commands\Firewall;

use App\Exceptions\GatewayApiException;

abstract class FirewallStoreCommand extends FirewallGatewayCommand
{
    abstract protected function firewallAction(): string;

    public function handle(): int
    {
        $name = $this->requiredArgument('name', 'The firewall rule name is required.');

        if (is_int($name)) {
            return $name;
        }

        $node = $this->firewallTargetNode();

        if (is_int($node)) {
            return $node;
        }

        $port = $this->stringOption('port');

        if ($port === null) {
            if (! $this->wantsJson() && $this->input->isInteractive()) {
                $answer = $this->ask('Port');
                $port = is_string($answer) && trim($answer) !== '' ? trim($answer) : null;
            }
        }

        if ($port === null) {
            return $this->failValidation('port', 'The firewall rule port is required.');
        }

        try {
            $response = $this->gatewayPost('/api/firewall-rules', $this->filledQuery([
                'action' => $this->firewallAction(),
                'name' => $name,
                'node' => $node,
                'direction' => $this->stringOption('direction') ?? 'incoming',
                'source' => $this->stringOption('from') ?? 'any',
                'destination' => $this->stringOption('to'),
                'port' => $port,
                'protocol' => $this->stringOption('protocol') ?? 'tcp',
                'reason' => $this->stringOption('reason'),
            ]));
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        return $this->renderSuccess($response);
    }
}
