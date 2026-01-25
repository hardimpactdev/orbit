<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\CaddyfileGenerator;
use App\Services\ConfigManager;
use App\Services\DockerManager;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;

final class ReverbSetupCommand extends Command
{
    protected $signature = 'reverb:setup
        {--enable : Enable and start the Reverb service}
        {--disable : Disable and stop the Reverb service}
        {--app-id= : Custom app ID}
        {--app-key= : Custom app key}
        {--app-secret= : Custom app secret}';

    protected $description = 'Setup Laravel Reverb WebSocket service';

    public function handle(
        ConfigManager $config,
        DockerManager $docker,
        CaddyfileGenerator $caddyGenerator
    ): int {
        if ($this->option('disable')) {
            return $this->disableReverb($config, $docker, $caddyGenerator);
        }

        return $this->setupReverb($config, $docker, $caddyGenerator);
    }

    protected function setupReverb(
        ConfigManager $config,
        DockerManager $docker,
        CaddyfileGenerator $caddyGenerator
    ): int {
        $this->info('Setting up Reverb WebSocket service...');

        // Create config directory
        $reverbConfigPath = $config->getConfigPath().'/reverb';
        if (! File::isDirectory($reverbConfigPath)) {
            File::makeDirectory($reverbConfigPath, 0755, true);
            $this->line('  Created config directory');
        }

        // Copy docker-compose from stubs
        $stubPath = base_path('stubs/reverb');
        File::copy($stubPath.'/docker-compose.yml', $reverbConfigPath.'/docker-compose.yml');
        File::copy($stubPath.'/Dockerfile', $reverbConfigPath.'/Dockerfile');
        File::copy($stubPath.'/entrypoint.sh', $reverbConfigPath.'/entrypoint.sh');
        $this->line('  Copied Docker configuration');

        // Generate .env with configuration
        $appId = $this->option('app-id') ?: $config->get('reverb.app_id', 'orbit');
        $appKey = $this->option('app-key') ?: $config->get('reverb.app_key', 'orbit-key');
        $appSecret = $this->option('app-secret') ?: $config->get('reverb.app_secret', 'orbit-secret');

        $envContent = <<<ENV
REVERB_APP_ID={$appId}
REVERB_APP_KEY={$appKey}
REVERB_APP_SECRET={$appSecret}
REVERB_HOST=0.0.0.0
REVERB_PORT=8080
ENV;

        File::put($reverbConfigPath.'/.env', $envContent);
        $this->line('  Generated environment configuration');

        // Save config
        $tld = $config->get('tld', 'test');
        $config->setReverbConfig([
            'app_id' => $appId,
            'app_key' => $appKey,
            'app_secret' => $appSecret,
            'host' => "reverb.{$tld}",
            'port' => 443,
        ]);
        $config->set('reverb.url', "https://reverb.{$tld}");

        // Enable the service
        $config->enableService('reverb');
        $this->line('  Enabled service in configuration');

        // Regenerate Caddyfile with Reverb entry
        $caddyGenerator->generate();
        $this->line('  Updated Caddy configuration');

        if ($this->option('enable')) {
            $this->line('');
            $this->info('Building Reverb container...');

            // Build the Docker image
            $buildResult = Process::path($reverbConfigPath)
                ->timeout(300)
                ->run('docker compose build');

            if (! $buildResult->successful()) {
                $this->error('Failed to build Reverb container:');
                $this->line($buildResult->errorOutput());

                return 1;
            }
            $this->line('  Docker image built');

            // Start the service
            $this->info('Starting Reverb service...');
            $docker->start('reverb');
            $this->line('  Service started');

            // Reload Caddy
            $caddyGenerator->reload();
            $this->line('  Caddy reloaded');
        }

        $this->line('');
        $this->info('Reverb setup complete!');
        $this->line('');
        $this->line('WebSocket URL: <comment>wss://reverb.'.$tld.'</comment>');
        $this->line('App ID:        <comment>'.$appId.'</comment>');
        $this->line('App Key:       <comment>'.$appKey.'</comment>');
        $this->line('');
        $this->line('Add to your Laravel app .env:');
        $this->line('  REVERB_APP_ID='.$appId);
        $this->line('  REVERB_APP_KEY='.$appKey);
        $this->line('  REVERB_APP_SECRET='.$appSecret);
        $this->line('  REVERB_HOST=reverb.'.$tld);
        $this->line('  REVERB_PORT=443');
        $this->line('  REVERB_SCHEME=https');

        if (! $this->option('enable')) {
            $this->line('');
            $this->line('Run <comment>orbit start</comment> to start all services including Reverb.');
        }

        return 0;
    }

    protected function disableReverb(
        ConfigManager $config,
        DockerManager $docker,
        CaddyfileGenerator $caddyGenerator
    ): int {
        $this->info('Disabling Reverb service...');

        // Stop the container
        $docker->stop('reverb');
        $this->line('  Service stopped');

        // Disable in config
        $config->disableService('reverb');
        $this->line('  Disabled in configuration');

        // Regenerate Caddyfile without Reverb
        $caddyGenerator->generate();
        $caddyGenerator->reload();
        $this->line('  Caddy updated');

        $this->info('Reverb service disabled.');

        return 0;
    }
}
