<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\TaskVms\TaskVmException;
use App\Infrastructure\Processes\CommandDeadline;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use SensitiveParameter;
use Throwable;

/**
 * Registers and revokes a task VM group model key at CLIProxyAPI. Management credentials and group keys only
 * travel in headers or JSON bodies.
 */
final readonly class TaskVmModelKeys
{
    /** A running command deadline, such as the inline run of a warm task VM claim, also ends each request. */
    public function __construct(
        private CommandDeadline $deadline = new CommandDeadline,
    ) {}

    public function ensure(string $origin, #[SensitiveParameter] string $management, #[SensitiveParameter] string $anchor, #[SensitiveParameter] string $key): void
    {
        $this->validateKeys($anchor, $key);
        $this->anchor($origin, $management, $anchor);
        if (! in_array($key, $this->keys($origin, $management), true)) {
            $this->patch($origin, $management, $key, $key);
        }
        $this->awaitStatus($origin, $key, 200);
        $this->awaitStatus($origin, '', 401);
    }

    public function revoke(string $origin, #[SensitiveParameter] string $management, #[SensitiveParameter] string $anchor, #[SensitiveParameter] string $key): void
    {
        if ($anchor === $key) {
            throw $this->failed();
        }
        $this->validateKeys($anchor, $key);
        $this->anchor($origin, $management, $anchor);
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $keys = $this->keys($origin, $management);
            if (! in_array($anchor, $keys, true)) {
                throw $this->failed();
            }
            if (! in_array($key, $keys, true)) {
                $this->awaitStatus($origin, $key, 401);
                $this->awaitStatus($origin, '', 401);

                return;
            }
            $this->patch($origin, $management, $key, '');
        }
        throw $this->failed();
    }

    private function anchor(string $origin, #[SensitiveParameter] string $management, #[SensitiveParameter] string $anchor): void
    {
        if (! in_array($anchor, $this->keys($origin, $management), true)) {
            $this->patch($origin, $management, $anchor, $anchor);
        }
        if (! in_array($anchor, $this->keys($origin, $management), true)) {
            throw $this->failed();
        }
        $this->awaitStatus($origin, $anchor, 200);
        $this->awaitStatus($origin, '', 401);
    }

    /** @return list<string> */
    private function keys(string $origin, #[SensitiveParameter] string $management): array
    {
        $response = $this->request($origin.'/v0/management/api-keys', 'GET', $management);
        $keys = $response->json('api-keys');
        if ($response->status() !== 200 || ! is_array($keys) || ! array_is_list($keys) || ! array_all($keys, static fn (mixed $value): bool => is_string($value))) {
            throw $this->failed();
        }

        return $keys;
    }

    private function patch(string $origin, #[SensitiveParameter] string $management, #[SensitiveParameter] string $old, #[SensitiveParameter] string $new): void
    {
        if ($this->request($origin.'/v0/management/api-keys', 'PATCH', $management, ['old' => $old, 'new' => $new])->status() !== 200) {
            throw $this->failed();
        }
    }

    private function awaitStatus(string $origin, #[SensitiveParameter] string $key, int $status): void
    {
        for ($attempt = 0; $attempt < 20; $attempt++) {
            if ($this->request($origin.'/v1/models', 'GET', $key)->status() === $status) {
                return;
            }
            Sleep::for(100)->milliseconds();
        }
        throw $this->failed();
    }

    /** @param array<string, string>|null $body */
    private function request(string $url, string $method, #[SensitiveParameter] string $token, #[SensitiveParameter] ?array $body = null): Response
    {
        try {
            $timeout = $this->deadline->cap(5.0);
            $request = Http::timeout($timeout)->connectTimeout(min(3.0, $timeout))->withoutRedirecting()->acceptJson();
            if ($token !== '') {
                $request = $request->withToken($token);
            }

            return $request->send($method, $url, $body === null ? [] : ['json' => $body]);
        } catch (Throwable) {
            throw $this->failed();
        }
    }

    private function validateKeys(#[SensitiveParameter] string $anchor, #[SensitiveParameter] string $key): void
    {
        if ($anchor === $key || preg_match('/\A[a-f0-9]{64}\z/D', $anchor) !== 1 || preg_match('/\A[a-f0-9]{64}\z/D', $key) !== 1) {
            throw $this->failed();
        }
    }

    private function failed(): TaskVmException
    {
        return new TaskVmException('task_vm.model_key_failed', 'The group model key could not be confirmed with CLIProxyAPI.', 502);
    }
}
