<?php

declare(strict_types=1);

namespace App\Commands\App;

use App\Exceptions\GatewayApiException;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class AppExecCommand extends AppGatewayCommand
{
    protected $signature = 'app:exec
        {app? : App name or hostname}
        {cmd?* : Command to run inside the app runtime container}
        {--json : Output JSON}';

    protected $description = 'Run a command inside an app FrankenPHP runtime container.';

    public function handle(): int
    {
        $command = $this->commandTokens();

        if ($command === []) {
            return $this->failValidation('command', 'A command to execute is required.');
        }

        $selector = $this->stringArgument('app');
        $hostCwd = $this->hostCwdFromEnv();

        if ($selector === null && $hostCwd === null) {
            return $this->failValidation('app', 'App is required.');
        }

        try {
            $response = $selector === null
                ? $this->gatewayPost('/api/apps/exec/by-path', [
                    'command' => $command,
                    'host_cwd' => $hostCwd,
                ])
                : $this->gatewayPost($this->apiAppPath($selector, '/exec'), [
                    'command' => $command,
                ]);
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        return $this->emitExecResponse($response);
    }

    /**
     * @return list<string>
     */
    private function commandTokens(): array
    {
        $raw = $this->argument('cmd');

        if (! is_array($raw)) {
            return [];
        }

        $tokens = [];

        foreach ($raw as $token) {
            if (! is_string($token) || $token === '') {
                continue;
            }

            $tokens[] = $token;
        }

        return $tokens;
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function emitExecResponse(array $response): int
    {
        if ($this->wantsJson()) {
            return $this->renderSuccess($response);
        }

        $data = $this->successData($response);
        $stdout = is_string($data['stdout'] ?? null) ? $data['stdout'] : '';
        $stderr = is_string($data['stderr'] ?? null) ? $data['stderr'] : '';
        $exitCode = is_int($data['exit_code'] ?? null) ? $data['exit_code'] : self::SUCCESS;

        $this->writePassthrough($stdout, $stderr);

        return $exitCode;
    }

    private function writePassthrough(string $stdout, string $stderr): void
    {
        if ($stdout !== '') {
            $this->output->write($stdout);
        }

        if ($stderr === '') {
            return;
        }

        $errorStream = $this->errorStream();

        if ($errorStream !== null) {
            $errorStream->write($stderr);

            return;
        }

        $this->output->write($stderr);
    }

    private function hostCwdFromEnv(): ?string
    {
        $hostCwd = getenv('ORBIT_HOST_CWD');

        return is_string($hostCwd) && trim($hostCwd) !== '' ? trim($hostCwd) : null;
    }

    private function errorStream(): ?OutputInterface
    {
        $underlying = method_exists($this->output, 'getOutput')
            ? $this->output->getOutput()
            : null;

        if ($underlying instanceof ConsoleOutputInterface) {
            return $underlying->getErrorOutput();
        }

        return null;
    }
}
