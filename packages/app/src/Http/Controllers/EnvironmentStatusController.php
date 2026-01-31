<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Ui\Http\Controllers;

use HardImpact\Orbit\Core\Models\Environment;
use HardImpact\Orbit\Core\Services\DoctorService;
use HardImpact\Orbit\Core\Services\HorizonService;
use HardImpact\Orbit\Core\Services\MacPhpFpmConfigService;
use HardImpact\Orbit\Core\Services\OrbitCli\StatusService;

class EnvironmentStatusController extends Controller
{
    public function __construct(
        protected StatusService $status,
        protected DoctorService $doctor,
        protected MacPhpFpmConfigService $macPhpFpm,
        protected HorizonService $horizon,
    ) {}

    /**
     * Get environment status (services, PHP versions, etc.).
     */
    public function status(Environment $environment)
    {
        if ($environment->is_local) {
            $this->macPhpFpm->ensureConfigured();
        }

        $result = $this->status->status($environment);

        // Transform horizon service based on whether this is the dev or production instance
        if ($result['success'] && isset($result['data']['services']['horizon'])) {
            // Remove the generic horizon entry from CLI
            unset($result['data']['services']['horizon']);

            // Add the correct horizon service for this instance
            $serviceKey = $this->horizon->getServiceKey();
            $result['data']['services'][$serviceKey] = $this->horizon->getStatusInfo();
        }

        return response()->json($result);
    }

    /**
     * Get projects status.
     */
    public function projects(Environment $environment)
    {
        $result = $this->status->projects($environment);

        return response()->json($result);
    }

    /**
     * Run health checks on the environment (doctor).
     */
    public function runDoctor(Environment $environment)
    {
        $result = $this->doctor->runChecks($environment);

        return response()->json($result);
    }

    /**
     * Run quick connectivity check on the environment.
     */
    public function quickCheck(Environment $environment)
    {
        $result = $this->doctor->quickCheck($environment);

        return response()->json($result);
    }

    /**
     * Attempt to fix a specific doctor issue.
     */
    public function fixIssue(Environment $environment, string $check)
    {
        $result = $this->doctor->fixIssue($environment, $check);

        return response()->json($result);
    }
}
