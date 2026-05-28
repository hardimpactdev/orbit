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
    ): int {
        $input = $this->resolveInput($inputResolver, $selector);

        if ($input instanceof ProfileInputFailure) {
            return $this->renderProfileFailure($input->code, $input->message, $input->meta);
        }

        return $this->runProfile($input, $selector, $renderer);
    }

    private function runProfile(ProfileInput $input, ProfileAppSelector $selector, ProfileHumanRenderer $renderer): int
    {
        try {
            $response = $this->profileThroughGateway($input);
        } catch (GatewayApiException $exception) {
            if (
                $input->targetWasOmitted
                && $this->canPromptForApp()
                && $exception->gatewayErrorCode() === 'app.not_found'
            ) {
                return $this->runPromptedProfile($input, $selector, $renderer);
            }

            return $this->renderProfileGatewayFailure($exception);
        }

        return $this->renderProfileResponse($response, $renderer);
    }

    private function runPromptedProfile(ProfileInput $input, ProfileAppSelector $selector, ProfileHumanRenderer $renderer): int
    {
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

        return $this->runProfile($input->withTarget($selected), $selector, $renderer);
    }

    private function renderProfileResponse(array $response, ProfileHumanRenderer $renderer): int
    {
        if ($this->wantsJson()) {
            return $this->renderSuccess($response, ['warnings' => []]);
        }

        $data = $this->profileData($response);

        if ($data === []) {
            return $this->renderProfileFailure('profile_request_failed', 'Failed to complete profile request.');
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
    private function profileThroughGateway(ProfileInput $input): array
    {
        return $this->gatewayGet('/api/profile', $input->query());
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

        return self::FAILURE;
    }
}
