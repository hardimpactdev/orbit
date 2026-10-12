<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmSettings;
use App\Domain\TaskVms\TaskVmUpCloud;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The UpCloud HTTPS API for task VMs. The token comes from the Gateway-owned file in `task_vms.upcloud.token_file`
 * on each request and goes only to {@see self::Origin}. Every answer is a JSON object, or null for a 404 that the
 * caller allows. Any other failure is `task_vm.upcloud_failed`; the message never holds the token.
 */
final readonly class UpCloudApi
{
    public const string Origin = 'https://api.upcloud.com/1.3';

    private const string TokenPattern = '/^token:\s*[\'"]?(ucat_[A-Za-z0-9_-]+)[\'"]?\s*(?:#.*)?$/m';

    public function __construct(private TaskVmSettings $settings) {}

    public function upcloud(): TaskVmUpCloud
    {
        return $this->settings->upcloud ?? throw new TaskVmException('task_vm.upcloud_not_configured', 'The UpCloud overflow of task VMs is not configured.', 409);
    }

    /**
     * @param  array<string, mixed>|null  $body
     * @return array<string, mixed>|null
     */
    public function request(string $method, string $path, ?array $body = null, bool $allowMissing = false): ?array
    {
        $token = $this->token();
        try {
            $response = Http::connectTimeout(5)->timeout(60)->acceptJson()->withToken($token)
                ->withOptions(['allow_redirects' => false])
                ->send($method, self::Origin.'/'.ltrim($path, '/'), $body === null ? [] : ['json' => $body]);
        } catch (Throwable $exception) {
            throw new TaskVmException('task_vm.upcloud_failed', "The UpCloud request [{$method} {$path}] did not return: ".mb_substr($exception->getMessage(), 0, 300), 502);
        }
        if ($allowMissing && $response->status() === 404) {
            return null;
        }
        if (! $response->successful()) {
            throw new TaskVmException('task_vm.upcloud_failed', "UpCloud refused [{$method} {$path}] with HTTP {$response->status()}: ".self::error($response), 502);
        }
        if (trim($response->body()) === '') {
            return [];
        }
        $json = $response->json();
        if (! is_array($json) || array_is_list($json) && $json !== []) {
            throw new TaskVmException('task_vm.upcloud_failed', "UpCloud answered [{$method} {$path}] with no JSON object.", 502);
        }

        /** @var array<string, mixed> $json */
        return $json;
    }

    /** The token from the protected file: a regular file of the Gateway user with mode 0600 and one `token: ucat_…` line. */
    private function token(): string
    {
        $path = $this->upcloud()->tokenFile;
        $stat = is_link($path) ? false : @stat($path);
        if ($stat === false || ($stat['mode'] & 0170000) !== 0100000 || ($stat['mode'] & 0777) !== 0600
            || $stat['uid'] !== posix_geteuid() || $stat['size'] > 65536) {
            throw new TaskVmException('task_vm.upcloud_token_unavailable', 'The UpCloud token file must be a regular file of the Gateway user with mode 0600.', 500);
        }
        $text = @file_get_contents($path);
        if (! is_string($text) || preg_match_all(self::TokenPattern, $text, $matches) !== 1) {
            throw new TaskVmException('task_vm.upcloud_token_unavailable', 'The UpCloud token file must hold one `token: ucat_…` line.', 500);
        }

        return $matches[1][0];
    }

    private static function error(Response $response): string
    {
        $code = $response->json('error.error_code');
        $message = $response->json('error.error_message');

        return mb_substr(trim((is_string($code) ? $code.' ' : '').(is_string($message) ? $message : '')), 0, 300);
    }
}
