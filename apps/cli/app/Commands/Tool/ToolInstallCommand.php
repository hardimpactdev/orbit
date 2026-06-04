<?php

declare(strict_types=1);

namespace App\Commands\Tool;

final class ToolInstallCommand extends ToolGatewayCommand
{
    private const array STATUSES = ['installed', 'running'];

    /**
     * @var array<string, non-empty-list<string>>
     */
    private const array VERSION_FAMILY_CHOICES = [
        'mysql' => ['8', '9'],
    ];

    #[\Override]
    protected $signature = 'tool:install
        {tool? : Tool catalog name to install}
        {--app= : Resolve target by app selector}
        {--node= : Resolve target by node}
        {--instance= : Tool instance selector}
        {--tool-version= : Version or version family to install}
        {--runtime= : Runtime family to use}
        {--status=installed : Desired state after install (installed|running)}
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'Install a managed tool through the gateway.';

    public function handle(): int
    {
        $tool = $this->requireToolArgument();

        if (is_int($tool)) {
            return $tool;
        }

        $status = (string) $this->option('status');

        if (! in_array($status, self::STATUSES, true)) {
            return $this->failValidation('status', "Invalid --status value '{$status}'. Valid values: installed, running.", [
                'value' => $status,
                'reason' => 'unsupported_value',
            ]);
        }

        $payload = $this->toolTargetPayload(requireTarget: true);

        if (is_int($payload)) {
            return $payload;
        }

        $version = $this->installVersion($tool);

        if (is_int($version)) {
            return $version;
        }

        return $this->streamToolAction($tool, 'install', [
            ...$payload,
            ...$this->filledQuery([
                'instance' => $this->stringOption('instance'),
                'version' => $version,
                'runtime' => $this->stringOption('runtime'),
            ]),
            'status' => $status,
        ]);
    }

    private function installVersion(string $tool): string|int|null
    {
        $version = $this->stringOption('tool-version');

        if ($version !== null) {
            return $version;
        }

        $choices = self::VERSION_FAMILY_CHOICES[$this->normalizedTool($tool)] ?? [];

        if (count($choices) <= 1) {
            return null;
        }

        if (! $this->wantsJson() && $this->input->isInteractive()) {
            $answer = $this->choice('Version', $choices, $choices[0]);

            if (is_string($answer) && trim($answer) !== '') {
                return trim($answer);
            }
        }

        return $this->failValidation('version', "Tool '{$tool}' requires a version selection.", [
            'reason' => 'required',
            'tool' => $tool,
            'supported_version_families' => $choices,
        ]);
    }

    private function normalizedTool(string $tool): string
    {
        return mb_strtolower(trim($tool));
    }
}
