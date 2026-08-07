<?php

declare(strict_types=1);

namespace App\Commands\Tool;

final class ToolInstallCommand extends ToolGatewayCommand
{
    #[\Override]
    protected $signature = 'tool:install
        {tool? : Tool catalog name to install}
        {--instance= : Resolve target by instance selector}
        {--node= : Resolve target by node}
        {--tool-version= : Version or version family to install}
        {--user=* : Additional OS user to install a user-scoped CLI tool for}
        {--with-process : Also configure the related service process (default for service tools)}
        {--no-process : Install the capability only; do not configure the related service process}
        {--json : Output JSON}
        {--stream-json : Stream newline-delimited JSON progress frames}';

    #[\Override]
    protected $description = 'Install a managed tool through the gateway.';

    public function handle(): int
    {
        $tool = $this->requireToolArgument();

        if (is_int($tool)) {
            return $tool;
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

        $installConfig = $this->installConfigPayload($tool);

        if (is_int($installConfig)) {
            return $installConfig;
        }

        return $this->streamToolAction($tool, 'install', [
            ...$payload,
            ...$this->filledQuery([
                'version' => $this->stringOption('tool-version'),
            ]),
            'with_process' => ! $this->option('no-process'),
            ...($installConfig !== [] ? ['config' => $installConfig] : []),
        ]);
    }

    /**
     * @return array<string, mixed>|int
     */
    private function installConfigPayload(string $tool): array|int
    {
        $users = $this->arrayOption('user');

        if ($users === []) {
            return [];
        }

        return [
            'install_users' => $users,
        ];
    }

    /**
     * @return list<string>
     */
    private function arrayOption(string $key): array
    {
        $value = $this->option($key);

        if (! is_array($value)) {
            return [];
        }

        $items = [];

        array_walk($value, static function (mixed $item) use (&$items): void {
            if (is_string($item)) {
                $items[] = $item;
            }
        });

        return $items;
    }
}
