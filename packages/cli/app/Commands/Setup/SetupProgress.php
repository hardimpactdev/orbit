<?php

namespace App\Commands\Setup;

trait SetupProgress
{
    protected int $currentStep = 0;

    protected int $totalSteps = 0;

    protected bool $jsonOutput = false;

    protected function initProgress(int $totalSteps): void
    {
        $this->totalSteps = $totalSteps;
        $this->currentStep = 0;
    }

    protected function stepStart(string $name): void
    {
        $this->currentStep++;

        if ($this->jsonOutput) {
            $this->outputJson([
                'type' => 'step',
                'step' => $this->currentStep,
                'total' => $this->totalSteps,
                'name' => $name,
                'status' => 'running',
            ]);
        } else {
            $this->output->writeln("<info>Step {$this->currentStep}/{$this->totalSteps}: {$name}</info>");
        }
    }

    protected function stepComplete(string $name): void
    {
        if ($this->jsonOutput) {
            $this->outputJson([
                'type' => 'step',
                'step' => $this->currentStep,
                'total' => $this->totalSteps,
                'name' => $name,
                'status' => 'completed',
            ]);
        } else {
            $this->output->writeln("<info>✓ {$name}</info>");
        }
    }

    protected function stepError(string $name, string $error): void
    {
        if ($this->jsonOutput) {
            $this->outputJson([
                'type' => 'step',
                'step' => $this->currentStep,
                'total' => $this->totalSteps,
                'name' => $name,
                'status' => 'error',
                'error' => $error,
            ]);
        } else {
            $this->output->writeln("<error>✗ {$name}: {$error}</error>");
        }
    }

    protected function progressInfo(string $message): void
    {
        if ($this->jsonOutput) {
            $this->outputJson([
                'type' => 'info',
                'message' => $message,
            ]);
        } else {
            $this->output->writeln("  → {$message}");
        }
    }

    protected function setupComplete(): void
    {
        if ($this->jsonOutput) {
            $this->outputJson([
                'type' => 'complete',
                'success' => true,
                'message' => 'Orbit setup complete!',
            ]);
        } else {
            $this->output->writeln('<info>Orbit setup complete!</info>');
        }
    }

    protected function setupFailed(string $error): void
    {
        if ($this->jsonOutput) {
            $this->outputJson([
                'type' => 'complete',
                'success' => false,
                'error' => $error,
            ]);
        } else {
            $this->output->writeln("<error>Setup failed: {$error}</error>");
        }
    }

    protected function outputJson(array $data): void
    {
        $this->output->writeln(json_encode($data, JSON_UNESCAPED_SLASHES));
    }
}
