<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Tools\CredentialsToolRequest;
use App\Http\Gateway\Responses\Tools\ToolCredentialsResponse;
use App\Models\Node;
use App\Services\Tools\ToolCredentialsReader;
use App\Services\Tools\ToolRegistryFailure;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('tool:credentials
    {tool? : Tool catalog name to read credentials for}
    {--app= : Resolve target by app selector}
    {--node= : Resolve target by node}
    {--json : Output JSON}')]
#[Description('Read managed tool credentials')]
class ToolCredentialsCommand extends Command
{
    public function handle(ToolCredentialsReader $reader): int
    {
        $tool = $this->stringArgument('tool');
        $node = $this->stringOption('node');
        $app = $this->stringOption('app');

        if ($tool === null) {
            return $this->failCommand(
                code: 'validation_failed',
                message: 'A tool name is required.',
                meta: ['field' => 'tool'],
            );
        }

        $result = $this->isGatewayCaller()
            ? $reader->read($tool, node: $node, app: $app)
            : $this->readViaGateway($tool, node: $node, app: $app);

        if ($result instanceof GatewayApiException) {
            return $this->failCommand(
                code: $result->errorCode() ?? 'gateway_unavailable',
                message: $result->getMessage() !== ''
                    ? $result->getMessage()
                    : 'Gateway connection is required to read tool credentials.',
                meta: $result->errorMeta(),
            );
        }

        if ($result instanceof ToolRegistryFailure) {
            return $this->failCommand(
                code: $result->code,
                message: $result->message,
                meta: $result->meta,
            );
        }

        if ($this->wantsJson()) {
            return $this->jsonSuccess(['credentials' => $result]);
        }

        $this->line("Credentials for {$result['tool']} on {$result['node']}:");
        foreach ($result['fields'] as $key => $value) {
            $this->line("  {$key}: {$value}");
        }

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>|GatewayApiException
     */
    private function readViaGateway(string $tool, ?string $node, ?string $app): array|GatewayApiException
    {
        try {
            $dto = app(GatewayConnector::class)
                ->send(new CredentialsToolRequest(tool: $tool, app: $app, node: $node))
                ->dto();
        } catch (GatewayApiException $e) {
            return $e;
        } catch (Throwable) {
            return new GatewayApiException(
                message: 'Gateway connection is required to read tool credentials.',
                errorCode: 'gateway_unavailable',
                errorMeta: [],
            );
        }

        /** @var ToolCredentialsResponse $dto */
        return $dto->credentials;
    }

    private function isGatewayCaller(): bool
    {
        return $this->callerRole() === 'gateway';
    }

    private function callerRole(): string
    {
        $localRole = Node::query()
            ->where('is_local', true)
            ->where('status', 'active')
            ->value('role');

        if (! is_string($localRole) || $localRole === '') {
            return 'control';
        }

        if (! in_array($localRole, ['gateway', 'app', 'control'], true)) {
            return 'unknown';
        }

        return $localRole;
    }

    private function stringArgument(string $name): ?string
    {
        $value = $this->argument($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function jsonSuccess(array $data): int
    {
        $this->line(json_encode([
            'success' => [
                'data' => $data,
                'meta' => (object) [],
            ],
        ], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function failCommand(string $code, string $message, array $meta): int
    {
        if ($this->wantsJson()) {
            $this->line(json_encode([
                'error' => [
                    'code' => $code,
                    'message' => $message,
                    'meta' => empty($meta) ? (object) [] : $meta,
                ],
            ], JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }

        $this->error($message);

        return self::FAILURE;
    }

    private function wantsJson(): bool
    {
        return (bool) $this->option('json');
    }
}
