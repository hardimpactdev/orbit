<?php

declare(strict_types=1);

namespace HardImpact\Orbit\App\Http\Controllers;

use HardImpact\Orbit\Core\Models\Environment;
use HardImpact\Orbit\Core\Services\OrbitCli\ServiceControlService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Handles service control operations for environments.
 *
 * This includes starting, stopping, restarting services,
 * viewing logs, and managing service configuration.
 */
class EnvironmentServiceController extends Controller
{
    public function __construct(
        protected ServiceControlService $serviceControl,
    ) {}

    /**
     * Start all services for an environment.
     */
    public function start(Request $request, Environment $environment): JsonResponse
    {
        $project = $request->input('project');
        $result = $this->serviceControl->start($environment, $project);

        return response()->json($result);
    }

    /**
     * Stop all services for an environment.
     */
    public function stop(Request $request, Environment $environment): JsonResponse
    {
        $project = $request->input('project');
        $result = $this->serviceControl->stop($environment, $project);

        return response()->json($result);
    }

    /**
     * Restart all services for an environment.
     */
    public function restart(Request $request, Environment $environment): JsonResponse
    {
        $project = $request->input('project');
        $result = $this->serviceControl->restart($environment, $project);

        return response()->json($result);
    }

    /**
     * Start a single Docker service.
     */
    public function startService(Environment $environment, string $service): JsonResponse
    {
        $result = $this->serviceControl->startService($environment, $service);

        return response()->json($result);
    }

    /**
     * Start a single host service.
     */
    public function startHostService(Environment $environment, string $service): JsonResponse
    {
        $result = $this->serviceControl->startHostService($environment, $service);

        return response()->json($result);
    }

    /**
     * Stop a single Docker service.
     */
    public function stopService(Environment $environment, string $service): JsonResponse
    {
        $result = $this->serviceControl->stopService($environment, $service);

        return response()->json($result);
    }

    /**
     * Stop a single host service.
     */
    public function stopHostService(Environment $environment, string $service): JsonResponse
    {
        $result = $this->serviceControl->stopHostService($environment, $service);

        return response()->json($result);
    }

    /**
     * Restart a single Docker service.
     */
    public function restartService(Environment $environment, string $service): JsonResponse
    {
        $result = $this->serviceControl->restartService($environment, $service);

        return response()->json($result);
    }

    /**
     * Restart a single host service.
     */
    public function restartHostService(Environment $environment, string $service): JsonResponse
    {
        $result = $this->serviceControl->restartHostService($environment, $service);

        return response()->json($result);
    }

    /**
     * Get logs for a single Docker service.
     */
    public function serviceLogs(Request $request, Environment $environment, string $service): JsonResponse
    {
        $since = $request->query('since');
        $result = $this->serviceControl->serviceLogs($environment, $service, 200, $since);

        return response()->json($result);
    }

    /**
     * Get logs for a host service (Caddy, PHP-FPM, Horizon).
     */
    public function hostServiceLogs(Request $request, Environment $environment, string $service): JsonResponse
    {
        $since = $request->query('since');
        $result = $this->serviceControl->hostServiceLogs($environment, $service, 200, $since);

        return response()->json($result);
    }

    /**
     * Show available services.
     */
    public function availableServices(Environment $environment): JsonResponse
    {
        $result = $this->serviceControl->available($environment);

        return response()->json($result);
    }

    /**
     * Enable a service.
     */
    public function enableService(Request $request, Environment $environment, string $service): JsonResponse
    {
        $options = $request->input('options', []);
        $result = $this->serviceControl->enable($environment, $service, $options);

        return response()->json($result);
    }

    /**
     * Disable a service.
     */
    public function disableService(Environment $environment, string $service): JsonResponse
    {
        $result = $this->serviceControl->disable($environment, $service);

        return response()->json($result);
    }

    /**
     * Update service config.
     */
    public function configureService(Request $request, Environment $environment, string $service): JsonResponse
    {
        $config = $request->input('config', []);
        $result = $this->serviceControl->configure($environment, $service, $config);

        return response()->json($result);
    }

    /**
     * Get service details.
     */
    public function serviceInfo(Environment $environment, string $service): JsonResponse
    {
        $result = $this->serviceControl->info($environment, $service);

        return response()->json($result);
    }
}
