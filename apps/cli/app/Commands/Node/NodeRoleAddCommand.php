<?php

declare(strict_types=1);

namespace App\Commands\Node;

use App\Commands\GatewayCommand;
use App\Exceptions\GatewayApiException;
use App\Exceptions\NodeWriteInputException;
use App\Services\Node\NodeRoleAddPayloadBuilder;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

final class NodeRoleAddCommand extends GatewayCommand
{
    #[\Override]
    protected $name = 'node role:add';

    #[\Override]
    protected $description = 'Add a hosted role to a node.';

    protected function configure(): void
    {
        parent::configure();

        $this->addArgument('node', InputArgument::REQUIRED, 'Name of the node');
        $this->addArgument('role', InputArgument::REQUIRED, 'Role to add');
        $this->addOption('tld', null, InputOption::VALUE_REQUIRED, 'Development TLD for app-dev');
        $this->addOption('redis-node', null, InputOption::VALUE_REQUIRED, 'Existing database node for websocket Redis');
        $this->addOption('s3-data-path', null, InputOption::VALUE_REQUIRED, 'Host data path for the s3 role');
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Output JSON');
    }

    public function handle(NodeRoleAddPayloadBuilder $payloadBuilder): int
    {
        $node = (string) $this->argument('node');
        $role = (string) $this->argument('role');

        try {
            $payload = $payloadBuilder->build(
                role: $role,
                tld: $this->stringOption('tld'),
                redisNode: $this->stringOption('redis-node'),
                s3DataPath: $this->stringOption('s3-data-path'),
            );
        } catch (NodeWriteInputException $exception) {
            return $this->renderFailure($exception->orbitCode, $exception->getMessage(), $exception->meta);
        }

        try {
            $response = $this->gatewayPost('/api/nodes/'.rawurlencode($node).'/roles', $payload);
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        return $this->renderSuccess($response);
    }

    private function stringOption(string $key): ?string
    {
        $value = $this->option($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
