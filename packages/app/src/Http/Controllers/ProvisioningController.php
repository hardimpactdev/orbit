<?php

namespace HardImpact\Orbit\Ui\Http\Controllers;

use HardImpact\Orbit\Core\Models\Environment;
use HardImpact\Orbit\Core\Models\Setting;
use HardImpact\Orbit\Core\Models\SshKey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;

class ProvisioningController extends Controller
{
    public function create(): \Inertia\Response
    {
        $sshKeys = SshKey::orderBy('is_default', 'desc')->orderBy('name')->get();
        $availableSshKeys = Setting::getAvailableSshKeys();

        return \Inertia\Inertia::render('provisioning/Create', [
            'sshKeys' => $sshKeys,
            'availableSshKeys' => $availableSshKeys,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'host' => 'required|string|max:255',
            'user' => 'required|string|max:255',
            'ssh_public_key' => 'required|string',
        ]);

        // Store the SSH key if it changed
        if ($validated['ssh_public_key'] !== Setting::getSshPublicKey()) {
            Setting::setSshPublicKey($validated['ssh_public_key']);
        }

        // Create the environment record immediately with provisioning status
        $environment = Environment::create([
            'name' => $validated['name'],
            'host' => $validated['host'],
            'user' => $validated['user'],
            'port' => 22,
            'is_local' => false,
            'status' => Environment::STATUS_PROVISIONING,
        ]);

        // Redirect to the environment show page immediately - provisioning runs in background
        return redirect()->route('environments.show', $environment);
    }

    public function run(Request $request, Environment $environment)
    {
        $validated = $request->validate([
            'ssh_public_key' => 'required|string',
        ]);

        // Handle local environment provisioning
        if ($environment->is_local) {
            return $this->runLocalProvisioning($environment);
        }

        // Handle remote environment provisioning
        // Clear old SSH host keys BEFORE starting provisioning
        // This must happen synchronously before the background process starts
        Process::run("ssh-keygen -R {$environment->host} 2>/dev/null");

        // Run provisioning in the background so the HTTP request returns immediately
        $sshKey = $validated['ssh_public_key'];
        $artisanPath = base_path('artisan');

        // Spawn the artisan command in the background
        $command = sprintf(
            'php %s environment:provision %d %s > /dev/null 2>&1 &',
            escapeshellarg($artisanPath),
            $environment->id,
            escapeshellarg((string) $sshKey)
        );

        // Use popen for background execution
        pclose(popen($command, 'r'));

        return response()->json([
            'started' => true,
            'message' => 'Provisioning started in background',
        ]);
    }

    protected function runLocalProvisioning(Environment $environment): \Illuminate\Http\JsonResponse
    {
        $tld = $environment->tld ?? 'test';
        $logPath = storage_path("logs/provision-{$environment->id}.log");

        // Ensure the CLI is installed
        $cliUpdate = app(\App\Services\CliUpdateService::class);
        if (! $cliUpdate->isInstalled()) {
            return response()->json([
                'success' => false,
                'error' => 'Orbit CLI not installed. Please install it first.',
            ], 400);
        }

        $pharPath = $cliUpdate->getPharPath();

        // Clear any existing log file
        if (file_exists($logPath)) {
            unlink($logPath);
        }

        // Spawn CLI setup command in background
        $command = sprintf(
            'nohup php %s setup --json --tld=%s > %s 2>&1 &',
            escapeshellarg($pharPath),
            escapeshellarg($tld),
            escapeshellarg($logPath)
        );

        // Use popen for background execution
        pclose(popen($command, 'r'));

        return response()->json([
            'started' => true,
            'message' => 'Local provisioning started in background',
        ]);
    }

    public function status(Environment $environment)
    {
        // For local environments, parse progress from CLI log file
        if ($environment->is_local && $environment->status === Environment::STATUS_PROVISIONING) {
            $this->parseCliProgress($environment);
            $environment->refresh();
        }

        return response()->json([
            'status' => $environment->status,
            'provisioning_step' => $environment->provisioning_step,
            'provisioning_total_steps' => $environment->provisioning_total_steps,
            'provisioning_log' => $environment->provisioning_log,
            'provisioning_error' => $environment->provisioning_error,
        ]);
    }

    protected function parseCliProgress(Environment $environment): void
    {
        $logPath = storage_path("logs/provision-{$environment->id}.log");

        if (! file_exists($logPath)) {
            return;
        }

        $content = file_get_contents($logPath);
        $lines = explode("\n", trim($content));

        $log = [];
        $currentStep = 0;
        $totalSteps = 15; // Mac setup has 15 steps
        $hasError = false;
        $errorMessage = null;

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if ($line === '0') {
                continue;
            }

            // Try to parse as JSON
            $decoded = json_decode($line, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                // It's a valid JSON line from the CLI
                if (isset($decoded['type'])) {
                    if ($decoded['type'] === 'step' && isset($decoded['message'])) {
                        $log[] = ['step' => $decoded['message']];
                        $currentStep = $decoded['step'] ?? $currentStep + 1;
                        $totalSteps = $decoded['total'] ?? $totalSteps;
                    } elseif ($decoded['type'] === 'info' && isset($decoded['message'])) {
                        $log[] = ['info' => $decoded['message']];
                    } elseif ($decoded['type'] === 'error' && isset($decoded['message'])) {
                        $log[] = ['error' => $decoded['message']];
                        $hasError = true;
                        $errorMessage = $decoded['message'];
                    } elseif ($decoded['type'] === 'success' && isset($decoded['message'])) {
                        // Setup completed successfully
                        $environment->update([
                            'status' => Environment::STATUS_ACTIVE,
                            'provisioning_step' => $totalSteps,
                            'provisioning_total_steps' => $totalSteps,
                            'provisioning_log' => $log,
                        ]);

                        return;
                    }
                }
            } elseif (stripos($line, 'error') !== false || stripos($line, 'failed') !== false) {
                // Not JSON, treat as raw output (might be error or debug)
                $log[] = ['error' => $line];
                $hasError = true;
                $errorMessage ??= $line;
            } else {
                $log[] = ['info' => $line];
            }
        }

        // Update environment with current progress
        $updateData = [
            'provisioning_step' => $currentStep,
            'provisioning_total_steps' => $totalSteps,
            'provisioning_log' => $log,
        ];

        if ($hasError) {
            $updateData['status'] = Environment::STATUS_ERROR;
            $updateData['provisioning_error'] = $errorMessage;
        }

        $environment->update($updateData);
    }

    public function checkServer(Request $request)
    {
        $validated = $request->validate([
            'host' => 'required|string|max:255',
            'user' => 'required|string|max:255',
        ]);

        $result = \App\Services\ProvisioningService::checkExistingSetup(
            $validated['host'],
            $validated['user']
        );

        return response()->json($result);
    }
}
