<?php

namespace App\Commands;

use App\Services\DockerManager;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;

class TrustCommand extends Command
{
    protected $signature = 'trust';

    protected $description = "Install Caddy's root CA certificate to trust local HTTPS certificates";

    public function handle(DockerManager $dockerManager): int
    {
        $this->warn('⚠️  The "trust" command is deprecated and will be removed in a future version.');
        $this->line('   Please use "orbit trust-root-ca" instead.');
        $this->newLine();

        $this->info('Installing Caddy root CA certificate...');

        // Check if Caddy container is running (uses batched cache if available)
        if (! $dockerManager->isRunning('orbit-caddy')) {
            $this->error('Caddy container is not running. Run: orbit start');

            return self::FAILURE;
        }

        // Extract the root CA from Caddy container
        $tempCert = '/tmp/caddy-root-ca.crt';

        $this->task('Extracting root CA from Caddy', function () use ($tempCert) {
            $result = Process::run("docker exec orbit-caddy cat /data/caddy/pki/authorities/local/root.crt > {$tempCert}");

            return file_exists($tempCert) && filesize($tempCert) > 0;
        });

        if (! file_exists($tempCert) || filesize($tempCert) === 0) {
            $this->error('Failed to extract root CA. Make sure Caddy has generated certificates.');
            $this->line('Try visiting https://localhost first to trigger certificate generation.');

            return self::FAILURE;
        }

        // Install to macOS Keychain
        $this->task('Adding to macOS Keychain (requires password)', function () use ($tempCert) {
            $result = Process::run("sudo security add-trusted-cert -d -r trustRoot -k /Library/Keychains/System.keychain {$tempCert}");

            return $result->successful();
        });

        // Cleanup
        @unlink($tempCert);

        $this->newLine();
        $this->info('Root CA installed! You may need to restart your browser.');
        $this->line('Your *.test sites should now show as secure.');

        return self::SUCCESS;
    }
}
