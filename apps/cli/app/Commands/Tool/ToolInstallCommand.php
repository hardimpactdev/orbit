<?php

declare(strict_types=1);

namespace App\Commands\Tool;

final class ToolInstallCommand extends ToolGatewayCommand
{
    private const array STATUSES = ['installed', 'running'];

    #[\Override]
    protected $signature = 'tool:install
        {tool? : Tool catalog name to install}
        {--app= : Resolve target by app selector}
        {--node= : Resolve target by node}
        {--tool-version= : Version or version family to install}
        {--status=installed : Desired state after install (installed|running)}
        {--with-process : Also configure the related service process (default for service tools)}
        {--no-process : Install the capability only; do not configure the related service process}
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

        if ($this->option('with-process') && $this->option('no-process')) {
            return $this->failValidation('process', 'Use only one of --with-process or --no-process.', [
                'reason' => 'conflicting_options',
            ]);
        }

        $payload = $this->toolTargetPayload(requireTarget: true);

        if (is_int($payload)) {
            return $payload;
        }

        return $this->streamToolAction($tool, 'install', [
            ...$payload,
            ...$this->filledQuery([
                'version' => $this->stringOption('tool-version'),
            ]),
            'status' => $status,
            'with_process' => ! $this->option('no-process'),
        ]);
    }
}
