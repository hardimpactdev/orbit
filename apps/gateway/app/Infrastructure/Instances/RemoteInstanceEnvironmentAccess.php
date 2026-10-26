<?php

declare(strict_types=1);

namespace App\Infrastructure\Instances;

use App\Domain\Instances\Environment\InstanceEnvironmentContext;
use App\Domain\Instances\Environment\InstanceEnvironmentReader;
use App\Domain\Instances\Environment\InstanceEnvironmentValidator;
use App\Domain\Instances\Environment\InstanceEnvironmentWriter;
use App\Domain\Instances\Environment\InstanceEnvironmentWriteResult;
use App\Domain\Instances\Environment\InstanceOperationPreflight;
use App\Domain\Instances\Environment\InstanceTestEnvironmentWriter;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use SensitiveParameter;
use Throwable;

/**
 * An Instance's `.env` and `.env.testing` through the Node agent's environment commands (docs/reference/environment-variables.md). The agent walks to the checkout or home
 * without following a link, accepts only the Instance user's regular file, and publishes a write with one rename.
 */
final readonly class RemoteInstanceEnvironmentAccess implements InstanceEnvironmentReader, InstanceEnvironmentWriter, InstanceOperationPreflight, InstanceTestEnvironmentWriter
{
    public function __construct(private AgentCommandRunner $agents) {}

    public function assertEnvironmentReadable(InstanceEnvironmentContext $context): void
    {
        if (($this->inspect($context, 'read-check')['state'] ?? null) !== 'ok') {
            $this->failRead();
        }
    }

    public function assertEnvironmentWritable(InstanceEnvironmentContext $context, int $requiredCapacityBytes): void
    {
        if (($this->inspect($context, 'write-check', $requiredCapacityBytes)['state'] ?? null) !== 'ok') {
            $this->failWritePreflight();
        }
    }

    public function read(InstanceEnvironmentContext $context): string
    {
        return $this->readContents($this->inspect($context, 'read'));
    }

    public function readWritableEnvironment(InstanceEnvironmentContext $context, int $requiredCapacityBytes): string
    {
        try {
            $outcome = $this->agents->run($context->node, 'instance.inspect-environment-sync', [
                ...$this->contextInput($context),
                'required_capacity' => $requiredCapacityBytes,
            ]);
        } catch (Throwable) {
            $this->failWritePreflight();
        }

        if (! is_array($outcome['preflight'] ?? null) || ($outcome['preflight']['state'] ?? null) !== 'ok') {
            $this->failWritePreflight();
        }

        return $this->readContents(is_array($outcome['existing'] ?? null) ? $outcome['existing'] : []);
    }

    /** @param array<string, mixed> $outcome */
    private function readContents(#[SensitiveParameter] array $outcome): string
    {

        if (($outcome['state'] ?? null) === 'missing') {
            throw new ResourceOperationException(
                errorCode: 'env.import_source_missing',
                message: 'The recorded Instance environment file does not exist.',
                status: 404,
            );
        }

        $contents = ($outcome['state'] ?? null) === 'ok' && is_string($outcome['content'] ?? null) ? base64_decode($outcome['content'], true) : false;

        if (! is_string($contents) || strlen($contents) > InstanceEnvironmentValidator::MaximumFileBytes) {
            $this->failRead();
        }

        return $contents;
    }

    public function write(InstanceEnvironmentContext $context, #[SensitiveParameter] string $contents): InstanceEnvironmentWriteResult
    {
        return $this->replace($context, $contents, '.env');
    }

    public function mergeTesting(InstanceEnvironmentContext $context, #[SensitiveParameter] string $contents, array $managedKeys): InstanceEnvironmentWriteResult
    {
        return $this->replace($context, $contents, InstanceTestEnvironmentWriter::FILE, $managedKeys);
    }

    /** @param  list<string>|null  $managedKeys  the keys to merge into the file; null replaces it */
    private function replace(InstanceEnvironmentContext $context, #[SensitiveParameter] string $contents, string $file, ?array $managedKeys = null): InstanceEnvironmentWriteResult
    {
        try {
            $outcome = $this->agents->run($context->node, 'instance.write-environment', [
                ...$this->input($context, $managedKeys === null ? 'write' : 'merge'),
                'file' => $file,
                'managed_keys' => $managedKeys ?? [],
                'content' => $contents,
            ]);
        } catch (Throwable) {
            return InstanceEnvironmentWriteResult::unconfirmed();
        }

        return match ($outcome['state'] ?? null) {
            'changed' => InstanceEnvironmentWriteResult::changed(),
            'unchanged' => InstanceEnvironmentWriteResult::unchanged(),
            'tracked' => $managedKeys !== null ? InstanceEnvironmentWriteResult::tracked() : InstanceEnvironmentWriteResult::unconfirmed(),
            'tracking_unknown' => $managedKeys !== null
                ? throw new ResourceOperationException('env.testing_tracking_unknown', 'Git cannot report whether the checkout tracks .env.testing, so the file was left unchanged.', 409)
                : InstanceEnvironmentWriteResult::unconfirmed(),
            'refused' => $this->failWrite(),
            // A failure after the replacement was in place cannot say which file is there.
            'failed' => ($outcome['installed'] ?? true) === false ? $this->failWrite() : InstanceEnvironmentWriteResult::unconfirmed(),
            default => InstanceEnvironmentWriteResult::unconfirmed(),
        };
    }

    /** @return array<string, mixed> */
    private function inspect(InstanceEnvironmentContext $context, string $mode, int $requiredCapacityBytes = 0): array
    {
        try {
            return $this->agents->run($context->node, 'instance.read-environment', [...$this->input($context, $mode), 'required_capacity' => $requiredCapacityBytes]);
        } catch (Throwable) {
            $mode === 'write-check' ? $this->failWritePreflight() : $this->failRead();
        }
    }

    /** @return array<string, mixed> */
    private function input(InstanceEnvironmentContext $context, string $mode): array
    {
        return ['mode' => $mode, ...$this->contextInput($context)];
    }

    /** @return array<string, mixed> */
    private function contextInput(InstanceEnvironmentContext $context): array
    {
        return [
            'base' => $context->path,
            'user' => $context->executionUser,
            'maximum' => InstanceEnvironmentValidator::MaximumFileBytes,
            'require_home' => $context->environment === 'production',
        ];
    }

    private function failRead(): never
    {
        throw new ResourceOperationException(
            errorCode: 'env.import_preflight_failed',
            message: 'The recorded Instance environment file cannot be read safely.',
            status: 409,
        );
    }

    private function failWritePreflight(): never
    {
        throw new ResourceOperationException(
            errorCode: 'env.write_preflight_failed',
            message: 'The recorded Instance environment file cannot be replaced safely.',
            status: 409,
        );
    }

    private function failWrite(): never
    {
        throw new ResourceOperationException(
            errorCode: 'env.write_failed',
            message: 'The Instance environment file replacement failed safely.',
            status: 409,
        );
    }
}
