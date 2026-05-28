<?php

declare(strict_types=1);

namespace App\Commands\App;

use App\Exceptions\GatewayApiException;

final class AppRootCommand extends AppGatewayCommand
{
    #[\Override]
    protected $signature = 'app:root
        {app? : App name or hostname}
        {root? : Document root relative to app path}
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'Change the document root for an app.';

    public function handle(): int
    {
        $selector = $this->stringArgument('app');
        $root = $this->stringArgument('root');

        if ($selector === null) {
            return $this->failValidation('app', 'App is required.');
        }

        if ($root === null) {
            return $this->failValidation('root', 'Root is required.');
        }

        try {
            $response = $this->gatewayPost($this->apiAppPath($selector, '/root'), [
                'root' => $root,
            ]);
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        return $this->renderSuccess($response);
    }
}
