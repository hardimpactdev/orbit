<?php

declare(strict_types=1);

namespace App\Commands\App;

use App\Exceptions\GatewayApiException;

final class AppRegisterCommand extends AppGatewayCommand
{
    #[\Override]
    protected $signature = 'app:register
        {name? : App name}
        {--node= : Target app node}
        {--path= : Existing app path on the target node}
        {--root=public : Document root relative to app path}
        {--php-version=8.5 : PHP version}
        {--domain= : Production domain}
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'Register or re-apply Orbit management for an app.';

    public function handle(): int
    {
        $name = $this->stringArgument('name');

        if ($name === null) {
            return $this->failValidation('name', 'App name is required.');
        }

        try {
            $response = $this->gatewayPost('/api/apps/register', [
                'name' => $name,
                'node' => $this->stringOption('node'),
                'path' => $this->stringOption('path'),
                'root' => $this->stringOption('root') ?? 'public',
                'php_version' => $this->stringOption('php-version') ?? '8.5',
                'domain' => $this->stringOption('domain'),
            ]);
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        return $this->renderSuccess($response);
    }
}
