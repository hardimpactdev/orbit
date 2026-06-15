<?php

declare(strict_types=1);

namespace App\Commands\Operation;

use App\Commands\Concerns\ResolvesHostContext;
use App\Commands\GatewayCommand;
use App\Exceptions\GatewayApiException;
use App\Services\Profile\ProfileAppSelector;
use App\Services\Profile\ProfileHumanRenderer;
use App\Services\Profile\ProfileInput;
use App\Services\Profile\ProfileInputFailure;
use App\Services\Profile\ProfileInputResolver;
use App\Services\Profile\ProfileRequestProfiler;
use Illuminate\Support\Str;

final class ProfileCommand extends GatewayCommand
{
    use ResolvesHostContext;

    #[\Override]
    protected $signature = 'profile
        {target? : Domain, app hostname, full URL, or absolute app path}
        {--app= : App name or hostname to profile}
        {--node= : Constrain app resolution to a node}
        {--uri= : Request URI to profile}
        {--as-first-user : Authenticate the profiled request as the first user}
        {--user= : Authenticate the profiled request as the given primary key}
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'Profile one Orbit-managed app HTTP request.';

    public function handle(
        ProfileInputResolver $inputResolver,
        ProfileAppSelector $selector,
        ProfileHumanRenderer $renderer,
        ProfileRequestProfiler $profiler,
    ): int {
        $input = $this->resolveInput($inputResolver, $selector);

        if ($input instanceof ProfileInputFailure) {
            return $this->renderProfileFailure($input->code, $input->message, $input->meta);
        }

        return $this->runProfile($input, $selector, $renderer, $profiler);
    }

    private function runProfile(
        ProfileInput $input,
        ProfileAppSelector $selector,
        ProfileHumanRenderer $renderer,
        ProfileRequestProfiler $profiler,
    ): int {
        try {
            $response = $this->resolveThroughGateway($input);
        } catch (GatewayApiException $exception) {
            if (
                $input->targetWasOmitted
                && $this->canPromptForApp()
                && $exception->gatewayErrorCode() === 'app.not_found'
            ) {
                return $this->runPromptedProfile($input, $selector, $renderer, $profiler);
            }

            return $this->renderProfileGatewayFailure($exception);
        }

        $resolution = $this->profileData($response);

        if ($resolution === []) {
            return $this->renderProfileFailure('profile_request_failed', 'Failed to complete profile request.');
        }

        return $this->runCallerProfile($input, $resolution, $renderer, $profiler);
    }

    private function runPromptedProfile(
        ProfileInput $input,
        ProfileAppSelector $selector,
        ProfileHumanRenderer $renderer,
        ProfileRequestProfiler $profiler,
    ): int {
        try {
            $selected = $this->promptForApp($selector, $input->node);
        } catch (GatewayApiException $exception) {
            return $this->renderProfileGatewayFailure($exception);
        }

        if ($selected === null) {
            return $this->renderProfileFailure('target_not_found', 'No linked app found for the requested profile target.', [
                'target' => $input->target,
            ]);
        }

        return $this->runProfile($input->withTarget($selected), $selector, $renderer, $profiler);
    }

    /**
     * @param  array<string, mixed>  $resolution
     */
    private function runCallerProfile(
        ProfileInput $input,
        array $resolution,
        ProfileHumanRenderer $renderer,
        ProfileRequestProfiler $profiler,
    ): int {
        $url = $this->resolvedProfileUrl($resolution);

        if ($url === null) {
            return $this->renderProfileFailure('profile_request_failed', 'Failed to complete profile request.');
        }

        $requestId = (string) Str::uuid();
        $authMode = $this->resolvedAuthMode($resolution, $input);
        $probe = $profiler->profile($url, $this->profileHeaders($authMode, $requestId, $input->user));
        $request = is_array($probe['request'] ?? null) ? $probe['request'] : [];

        if (($request['completed'] ?? false) !== true) {
            return $this->renderProfileFailure(
                'profile_request_failed',
                'Failed to complete profile request.',
                [
                    'origin' => 'caller',
                    'url' => $url,
                ],
                [
                    'request' => $request,
                    'timings' => is_array($probe['timings'] ?? null) ? $probe['timings'] : [],
                    'profile_error' => is_array($probe['error'] ?? null) ? $probe['error'] : ['message' => 'Profile request failed.'],
                ],
            );
        }

        $data = [
            ...$probe,
            'source' => 'baseline',
            'instrumented' => false,
            'auth_mode' => $authMode,
            'request_id' => $requestId,
            'origin' => 'caller',
            'target' => is_array($resolution['target'] ?? null) ? $resolution['target'] : [],
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

    private function resolveInput(ProfileInputResolver $resolver, ProfileAppSelector $selector): ProfileInput|ProfileInputFailure
    {
        $input = $resolver->resolve(
            target: $this->stringArgument('target'),
            app: $this->stringOption('app'),
            uriOption: $this->option('uri'),
            asFirstUser: (bool) $this->option('as-first-user'),
            user: $this->stringOption('user'),
            node: $this->stringOption('node'),
            appMarker: $this->appFromOrbitMarker(),
            hostCwd: $this->hostCwd(),
        );

        if (! $input instanceof ProfileInputFailure || ! $input->isMissingTarget() || ! $this->canPromptForApp()) {
            return $input;
        }

        try {
            $selected = $this->promptForApp($selector, $this->stringOption('node'));
        } catch (GatewayApiException $exception) {
            return new ProfileInputFailure(
                code: $exception->cliFailureCode(),
                message: $exception->getMessage(),
                meta: [],
            );
        }

        if ($selected === null) {
            return $input;
        }

        return $resolver->resolve(
            target: $selected,
            app: null,
            uriOption: $this->option('uri'),
            asFirstUser: (bool) $this->option('as-first-user'),
            user: $this->stringOption('user'),
            node: $this->stringOption('node'),
            appMarker: null,
            hostCwd: null,
        );
    }

    private function promptForApp(ProfileAppSelector $selector, ?string $node): ?string
    {
        return $selector->selectFromResponse(
            $this->gatewayGet('/api/apps', $this->filledQuery(['node' => $node])),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveThroughGateway(ProfileInput $input): array
    {
        return $this->gatewayGet('/api/profile/resolve', $input->query());
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private function profileData(array $response): array
    {
        $success = is_array($response['success'] ?? null) ? $response['success'] : [];
        $data = is_array($success['data'] ?? null) ? $success['data'] : [];

        return $data;
    }

    private function canPromptForApp(): bool
    {
        return ! $this->wantsJson() && $this->input->isInteractive();
    }

    /**
     * @param  array<string, mixed>  $resolution
     */
    private function resolvedProfileUrl(array $resolution): ?string
    {
        $request = is_array($resolution['request'] ?? null) ? $resolution['request'] : [];
        $url = $request['url'] ?? null;

        return is_string($url) && $url !== '' ? $url : null;
    }

    /**
     * @param  array<string, mixed>  $resolution
     */
    private function resolvedAuthMode(array $resolution, ProfileInput $input): string
    {
        $authMode = $resolution['auth_mode'] ?? null;

        return is_string($authMode) && $authMode !== '' ? $authMode : $input->authMode;
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

        if (is_string($user) && $user !== '') {
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

    private function renderProfileGatewayFailure(GatewayApiException $exception): int
    {
        if ($exception->hasGatewayError()) {
            $code = $exception->gatewayErrorCode() ?? $exception->cliFailureCode();
            $message = $exception->gatewayErrorMessage() ?? $exception->getMessage();

            if ($code === 'app.not_found') {
                $code = 'target_not_found';
                $message = 'No linked app found for the requested profile target.';
            }

            return $this->renderProfileFailure($code, $message, $exception->gatewayErrorMeta(), $exception->gatewayErrorData());
        }

        return $this->renderProfileFailure($exception->cliFailureCode(), $exception->getMessage());
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

        $this->line(match ($code) {
            'gateway_unavailable', 'gateway_unreachable_wireguard' => 'Gateway connection is required to resolve this profile target.',
            'target_not_found' => 'No linked app found for the requested profile target.',
            'profile_request_failed' => 'Failed to complete profile request.',
            default => $message,
        });

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
        $origin = $meta['origin'] ?? null;
        $origin = is_string($origin) ? trim($origin) : null;

        if ($origin !== 'caller') {
            return [];
        }

        $lines = ['Origin: caller'];

        $url = $meta['url'] ?? null;

        if (is_string($url) && trim($url) !== '') {
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
