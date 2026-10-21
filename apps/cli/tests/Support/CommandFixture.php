<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Contracts\Console\Kernel;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;
use Symfony\Component\Console\Input\ArgvInput;

final class CommandFixture
{
    /**
     * @param  list<string>  $arguments
     * @return array{status: int, output: string, stderr: string}
     */
    public static function run(array $arguments): array
    {
        $input = new ArgvInput(['orbit', ...$arguments]);
        $stream = fopen('php://memory', 'r+');
        $input->setStream($stream);
        $output = new CapturedConsoleOutput;

        try {
            $status = app(Kernel::class)->handle($input, $output);

            return ['status' => $status, 'output' => $output->stdout(), 'stderr' => $output->stderr()];
        } finally {
            fclose($stream);
        }
    }

    /**
     * @param  list<string>  $arguments
     * @param  list<array{class: class-string, body: array<string, mixed>, status?: int}>  $replies
     * @return array{status: int, output: string, stderr: string, requests: list<array<string, mixed>>}
     */
    public static function withReplies(array $arguments, array $replies): array
    {
        $requests = [];
        MockClient::global(['*' => static function (PendingRequest $pending) use (&$requests, &$replies): MockResponse {
            $request = $pending->getRequest();
            $requests[] = ['class' => $request::class, 'method' => $pending->getMethod()->value,
                'url' => $pending->getUrl(), 'body' => method_exists($request, 'body') ? $request->body()->all() : []];
            $reply = array_shift($replies);
            expect($reply)->not->toBeNull();
            expect($request::class)->toBe($reply['class']);

            return MockResponse::make($reply['body'], $reply['status'] ?? 200,
                ['X-Orbit-Request-Id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844']);
        }]);

        try {
            return [...self::run($arguments), 'requests' => $requests];
        } finally {
            MockClient::destroyGlobal();
        }
    }
}
