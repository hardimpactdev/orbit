<?php

declare(strict_types=1);

namespace App\Commands\Operation;

use App\Commands\LocalOnlyCommand;
use App\Services\Profile\ProfileHumanRenderer;
use App\Services\Profile\ProfileInput;
use App\Services\Profile\ProfileInputFailure;
use App\Services\Profile\ProfileInputResolver;
use App\Services\Profile\ProfileRequestProfiler;
use Illuminate\Support\Str;

use function Laravel\Prompts\text;

final class ProfileCommand extends LocalOnlyCommand
{
    #[\Override]
    protected $signature = 'profile
        {url? : Absolute HTTP or HTTPS URL to profile}
        {--as-first-user : Authenticate the profiled request as the first user}
        {--user= : Authenticate the profiled request as the given primary key}
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'Profile one HTTP request from this machine.';

    public function handle(
        ProfileInputResolver $inputResolver,
        ProfileHumanRenderer $renderer,
        ProfileRequestProfiler $profiler,
    ): int {
        $explicitUrl = $this->urlArgument();
        $input = $inputResolver->resolve(
            url: $explicitUrl,
            asFirstUser: (bool) $this->option('as-first-user'),
            user: $this->stringOption('user'),
        );

        if (
            $explicitUrl === null
            && $input instanceof ProfileInputFailure
            && $input->isUrlResolutionFailure()
            && $this->allowsInteractiveInput()
        ) {
            $input = $inputResolver->resolve(
                url: text(
                    label: 'URL to profile',
                    required: true,
                    transform: trim(...),
                    validate: $inputResolver->urlValidationMessage(...),
                ),
                asFirstUser: (bool) $this->option('as-first-user'),
                user: $this->stringOption('user'),
            );
        }

        if ($input instanceof ProfileInputFailure) {
            return $this->renderProfileFailure($input->code, $input->message, $input->meta);
        }

        return $this->runProfile($input, $renderer, $profiler);
    }

    private function runProfile(
        ProfileInput $input,
        ProfileHumanRenderer $renderer,
        ProfileRequestProfiler $profiler,
    ): int {
        $requestId = (string) Str::uuid();
        $probe = $profiler->profile(
            $input->url,
            $this->profileHeaders($input->authMode, $requestId, $input->user),
        );
        $request = is_array($probe['request'] ?? null) ? $probe['request'] : [];

        if (($request['completed'] ?? false) !== true) {
            return $this->renderProfileFailure(
                'profile_request_failed',
                'Failed to complete profile request.',
                [
                    'origin' => 'caller',
                    'url' => $input->url,
                ],
                [
                    'request' => $request,
                    'timings' => is_array($probe['timings'] ?? null) ? $probe['timings'] : [],
                    'profile_error' => is_array($probe['error'] ?? null)
                        ? $probe['error']
                        : ['message' => 'Profile request failed.'],
                ],
            );
        }

        $data = [
            ...$probe,
            'source' => 'baseline',
            'instrumented' => false,
            'auth_mode' => $input->authMode,
            'request_id' => $requestId,
            'origin' => 'caller',
        ];

        $headers = is_array($probe['response_headers'] ?? null) ? $probe['response_headers'] : [];
        $summary = $this->extractToolbarSummary($headers);

        if ($summary !== null) {
            $data['source'] = 'baseline+toolbar';
            $data['instrumented'] = true;
            $data['toolbar'] = $summary;
        }

        return $this->renderProfileData($data, $renderer);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function renderProfileData(array $data, ProfileHumanRenderer $renderer): int
    {
        if ($this->wantsJson()) {
            return $this->renderSuccess($data, ['warnings' => []]);
        }

        foreach ($renderer->lines($data) as $line) {
            $this->line($line);
        }

        return self::SUCCESS;
    }

    private function urlArgument(): ?string
    {
        $url = $this->argument('url');

        return is_string($url) && $url !== '' ? $url : null;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @return array<string, string>
     */
    private function profileHeaders(string $authMode, string $requestId, ?string $user): array
    {
        $headers = [
            'X-REQUEST-ID' => $requestId,
            'X-TOOLBAR-AUTH' => $authMode,
        ];

        if ($user !== null) {
            $headers['X-TOOLBAR-USER'] = $user;
        }

        return $headers;
    }

    /**
     * @param  array<string, mixed>  $responseHeaders
     * @return array<string, mixed>|null
     */
    private function extractToolbarSummary(array $responseHeaders): ?array
    {
        $encoded = $responseHeaders['x-toolbar-summary'] ?? null;

        if (! is_string($encoded) || $encoded === '') {
            return null;
        }

        $decoded = base64_decode($encoded, true);

        if ($decoded === false) {
            return null;
        }

        $summary = json_decode($decoded, associative: true);

        return is_array($summary) ? $summary : null;
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $data
     */
    private function renderProfileFailure(string $code, string $message, array $meta = [], array $data = []): int
    {
        if ($this->wantsJson()) {
            return $this->renderFailure($code, $message, $meta, $data);
        }

        $this->line($message);

        if ($code === 'profile_request_failed') {
            foreach ($this->profileFailureDiagnosticLines($meta, $data) as $line) {
                $this->line($line);
            }
        }

        return self::FAILURE;
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private function profileFailureDiagnosticLines(array $meta, array $data): array
    {
        if (($meta['origin'] ?? null) !== 'caller') {
            return [];
        }

        $lines = ['Origin: caller'];
        $url = $meta['url'] ?? null;

        if (is_string($url) && $url !== '') {
            $lines[] = "URL: {$url}";
        }

        $profileError = $data['profile_error'] ?? null;
        $profileErrorMessage = is_array($profileError) ? $profileError['message'] ?? null : null;

        if (is_string($profileErrorMessage) && trim($profileErrorMessage) !== '') {
            $lines[] = "Error: {$profileErrorMessage}";
        }

        return $lines;
    }
}
