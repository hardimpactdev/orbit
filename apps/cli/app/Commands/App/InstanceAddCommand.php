<?php

declare(strict_types=1);

namespace App\Commands\App;

use App\Exceptions\GatewayApiException;

final class InstanceAddCommand extends InstanceCommand
{
    #[\Override]
    protected $signature = 'instance:add
        {instance? : app.instance selector}
        {--driver=orbit : Instance driver (orbit|laravel-cloud)}
        {--node= : Orbit node for orbit driver}
        {--path= : Orbit instance path for orbit driver}
        {--root= : Orbit document root for orbit driver}
        {--domain= : Primary domain}
        {--cloud-app= : Laravel Cloud application id or name}
        {--cloud-environment= : Laravel Cloud environment id or name}
        {--cloud-application-id= : Laravel Cloud application id}
        {--cloud-application-name= : Laravel Cloud application name}
        {--cloud-environment-id= : Laravel Cloud environment id}
        {--cloud-environment-name= : Laravel Cloud environment name}
        {--cloud-organization-id= : Laravel Cloud organization id}
        {--cloud-organization-name= : Laravel Cloud organization name}
        {--php-extension=* : Required PHP extension for this instance}
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'Add an instance to an app.';

    public function handle(): int
    {
        $selector = $this->resolveInstanceSelector();

        if (is_int($selector)) {
            return $selector;
        }

        try {
            $response = $this->gatewayPost(
                $this->apiProjectPath($selector['app'], '/instances'),
                $this->addPayload($selector['instance']),
            );
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        if ($this->wantsJson()) {
            return $this->renderSuccess($response);
        }

        $instance = $this->instanceFromGatewayResponse($response);
        $name = $instance === null ? $selector['instance'] : $this->instanceString($instance, 'name');
        $app = $instance === null
            ? $selector['app']
            : $this->instanceString($instance, 'app');

        $this->line("Added instance '{$name}' to app '{$app}'.");

        if ($instance !== null) {
            $this->line('  driver: '.$this->instanceString($instance, 'driver'));
            $extensions = $this->extensionsLabel($instance);

            if ($extensions !== '—') {
                $this->line("  extensions: {$extensions}");
            }
        }

        return self::SUCCESS;
    }
}
