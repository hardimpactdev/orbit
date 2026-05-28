<?php

declare(strict_types=1);

namespace App\Commands\Node;

use App\Commands\Concerns\ResolvesDefaultNode;
use App\Commands\GatewayCommand;
use App\Exceptions\GatewayApiException;
use App\Exceptions\OrbitConfigStoreException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

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

        return $this->renderSuccess($response);
    }
}
