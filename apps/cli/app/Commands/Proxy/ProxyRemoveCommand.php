<?php

declare(strict_types=1);

namespace App\Commands\Proxy;

use App\Exceptions\GatewayApiException;

use function Laravel\Prompts\confirm;

final class ProxyRemoveCommand extends ProxyGatewayCommand
{
    #[\Override]
    protected $signature = 'proxy:remove
        {domain? : Existing custom proxy route domain}
        {--force : Confirm destructive operation without prompting}
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'Remove custom proxy route intent.';

    public function handle(): int
    {
        $domain = $this->stringArgument('domain');

        if ($domain === null) {
            return $this->failValidation('domain', 'The proxy route domain is required.');
        }

        $confirmation = $this->confirmRemoval($domain);

        if ($confirmation !== null) {
            return $confirmation;
        }

        try {
            $response = $this->gatewayDelete('/api/proxy-routes/'.rawurlencode($domain), [
                'destructive_consent' => true,
                'destructive_consent_source' => 'force',
            ]);
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        return $this->renderSuccess($response);
    }

    private function confirmRemoval(string $domain): ?int
    {
        if ($this->option('force') === true) {
            return null;
        }

        if ($this->wantsJson() || ! $this->input->isInteractive()) {
            return $this->renderFailure(
                'destructive_consent_required',
                'Use --force to remove this proxy route.',
                ['field' => 'force'],
            );
        }

        if (confirm(label: "Remove proxy route '{$domain}'?", default: false)) {
            return null;
        }

        return $this->renderFailure(
            'destructive_consent_required',
            'Operation cancelled.',
            ['field' => 'force'],
        );
    }
}
