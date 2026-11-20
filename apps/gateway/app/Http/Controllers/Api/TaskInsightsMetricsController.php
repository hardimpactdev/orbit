<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Tasks\TaskInsightsExposition;
use App\Http\Controllers\Controller;
use Illuminate\Http\Response;

/** The stored task insights totals for the Metrics role's Prometheus ([Task insights](/reference/tasks#prometheus-exporter)). */
final class TaskInsightsMetricsController extends Controller
{
    public function show(TaskInsightsExposition $exposition): Response
    {
        return response($exposition->render(), 200, ['Content-Type' => 'text/plain; version=0.0.4; charset=utf-8']);
    }
}
