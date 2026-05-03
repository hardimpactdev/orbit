<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\E2E\HcloudE2EImagePreparationOptions;
use App\Services\E2E\HcloudE2EImagePreparer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

#[Signature('e2e:prepare-hcloud-images
    {--force : Create hcloud servers and snapshots}
    {--role=all : Image role to prepare (all|control|gateway|devapp)}
    {--keep : Keep temporary hcloud servers and SSH keys}
    {--source-image=ubuntu-24.04 : hcloud base image}
    {--server-type=cx23 : hcloud server type for image builders}
    {--location=nbg1 : hcloud location for image builders}
    {--php=8.5 : PHP version to install into prepared images (sury noble has 8.5)}
    {--bootstrap-user=provisioner : Bootstrap user baked into prepared images}
    {--control-user=control : Control user baked into prepared control images}
    {--prefix=orbit-e2e : Resource name prefix}
    {--control-description=orbit-ready-control : Snapshot description for the ready control image}
    {--gateway-description=orbit-ready-gateway : Snapshot description for the ready gateway image}
    {--devapp-description=orbit-ready-devapp : Snapshot description for the ready development-app image}
    {--timeout=1800 : Timeout in seconds for long hcloud preparation steps}
    {--json : Output as JSON}')]
#[Description('Prepare hcloud snapshots used by ephemeral E2E tests')]
class E2EPrepareHcloudImagesCommand extends Command
{
    protected $hidden = true;

    public function handle(HcloudE2EImagePreparer $preparer): int
    {
        $role = (string) $this->option('role');

        if (! in_array($role, ['all', 'control', 'gateway', 'devapp'], true)) {
            return $this->failValidation('--role must be one of: all, control, gateway, devapp.');
        }

        $phpVersion = (string) $this->option('php');

        if ($phpVersion !== '8.5') {
            return $this->failValidation('--php must be: 8.5.');
        }

        $bootstrapUser = (string) $this->option('bootstrap-user');
        $controlUser = (string) $this->option('control-user');

        if (! $this->validLinuxUser($bootstrapUser) || ! $this->validLinuxUser($controlUser)) {
            return $this->failValidation('--bootstrap-user and --control-user must be simple Linux usernames.');
        }

        if ($controlUser === 'orbit') {
            return $this->failValidation('--control-user must not be orbit.');
        }

        $timeout = (int) $this->option('timeout');

        if ($timeout < 60) {
            return $this->failValidation('--timeout must be at least 60 seconds.');
        }

        try {
            $result = $preparer->prepare(new HcloudE2EImagePreparationOptions(
                roles: $role === 'all' ? ['control', 'gateway', 'devapp'] : [$role],
                force: (bool) $this->option('force'),
                keep: (bool) $this->option('keep'),
                sourceImage: (string) $this->option('source-image'),
                serverType: (string) $this->option('server-type'),
                location: (string) $this->option('location'),
                phpVersion: $phpVersion,
                bootstrapUser: $bootstrapUser,
                controlUser: $controlUser,
                prefix: (string) $this->option('prefix'),
                controlDescription: (string) $this->option('control-description'),
                gatewayDescription: (string) $this->option('gateway-description'),
                devappDescription: (string) $this->option('devapp-description'),
                timeoutSeconds: $timeout,
            ));
        } catch (RuntimeException $exception) {
            return $this->failCommand($exception->getMessage());
        }

        if ((bool) $this->option('json')) {
            $this->line(json_encode(['success' => ['data' => $result]], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->renderHuman($result);

        return self::SUCCESS;
    }

    /**
     * @param  array{
     *     provider: string,
     *     dry_run: bool,
     *     roles: list<array{role: string, description: string, server: string, snapshot_created: bool}>
     * }  $result
     */
    private function renderHuman(array $result): void
    {
        if ($result['dry_run']) {
            $this->line('Dry run. Pass --force to create hcloud image snapshots.');
        }

        foreach ($result['roles'] as $role) {
            $status = $role['snapshot_created'] ? 'prepared' : 'planned';

            $this->line("{$status}: {$role['role']} -> {$role['description']}");
        }

        if (! $result['dry_run']) {
            $this->line('Prepared '.count($result['roles']).' hcloud E2E image snapshots.');
        }
    }

    private function failValidation(string $message): int
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode([
                'error' => [
                    'code' => 'validation_failed',
                    'message' => $message,
                ],
            ], JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }

        $this->error($message);

        return self::FAILURE;
    }

    private function failCommand(string $message): int
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode([
                'error' => [
                    'code' => 'hcloud_e2e_image_prepare_failed',
                    'message' => $message,
                ],
            ], JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }

        $this->error($message);

        return self::FAILURE;
    }

    private function validLinuxUser(string $user): bool
    {
        return preg_match('/^[a-z_][a-z0-9_-]{0,31}$/', $user) === 1;
    }
}
