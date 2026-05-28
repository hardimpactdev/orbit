<?php

declare(strict_types=1);

namespace App\Commands\Tool;

use App\Commands\Concerns\ResolvesHostContext;
use App\Commands\GatewayCommand;
use App\Exceptions\GatewayApiException;
use App\Exceptions\OrbitConfigStoreException;

final class ToolShowCommand extends GatewayCommand
{
    use ResolvesHostContext;

    #[\Override]
    protected $signature = 'tool:show
        {tool? : Tool catalog name to inspect}
        {--app= : Resolve target by app selector}
        {--node= : Resolve target by node}
        {--live : Request gateway live inspection}
        {--json}';

    #[\Override]
    protected $description = 'Show one tool tracked by the gateway registry.';

    public function handle(): int
    {
        $tool = $this->stringArgument('tool');

        if ($tool === null) {
            return $this->renderFailure('validation_failed', 'The tool argument is required.', ['field' => 'tool']);
        }

        try {
            $response = $this->gatewayGet('/api/tools/'.rawurlencode($tool), $this->filledQuery([
                'app' => $this->stringOption('app'),
                'node' => $this->targetNodeOptionOrDefault(),
                'live' => $this->option('live') === true ? '1' : null,
            ]));
        } catch (OrbitConfigStoreException $exception) {
            return $this->renderFailure($exception->orbitCode, $exception->getMessage());
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        return $this->renderSuccess($response);
    }
}
