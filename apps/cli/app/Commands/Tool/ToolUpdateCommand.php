<?php

declare(strict_types=1);

namespace App\Commands\Tool;

final class ToolUpdateCommand extends ToolGatewayCommand
{
    #[\Override]
    protected $signature = 'tool:update
        {tool? : Tool catalog name to update}
        {--instance= : Resolve target by instance selector}
        {--node= : Resolve target by node}
        {--expected-version= : Expected version constraint}
        {--json : Output JSON}
        {--stream-json : Stream newline-delimited JSON progress frames}';

    #[\Override]
    protected $description = 'Update a managed tool through the gateway.';

    public function handle(): int
    {
        $tool = $this->stringArgument('tool');
        $version = $this->stringOption('expected-version');

        if ($tool === null && $version !== null) {
            return $this->failValidation(
                'expected_version',
                'The --expected-version option requires a tool argument.',
                [
                    'reason' => 'missing_tool',
                ],
            );
        }

        $payload = $this->toolTargetPayload();

        if (is_int($payload)) {
            return $payload;
        }

        if ($tool === null) {
            return $this->updateAll($payload);
        }

        return $this->updateOne($tool, [
            ...$payload,
            ...$this->filledQuery([
                'version' => $version,
            ]),
        ]);
    }

    /**
     * @param  array<string, string>  $payload
     */
    private function updateOne(string $tool, array $payload): int
    {
        return $this->streamToolAction($tool, 'update', $payload);
    }

    /**
     * @param  array<string, string>  $payload
     */
    private function updateAll(array $payload): int
    {
        return $this->streamToolBulkAction('update', $payload);
    }
}
