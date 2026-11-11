<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Hibernation\ScheduleInstanceRuntimeWakeAction;
use App\Domain\Hibernation\DevelopmentHibernationPolicy;
use App\Domain\Hibernation\HibernationWakeFailureStore;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Responses\RuntimeActivationPage;
use App\Models\Instance;
use App\Models\Node;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use SensitiveParameter;

#[RequiresNodeAccess(ServingNode::InstanceHost)]
final class RuntimeActivationsController extends Controller
{
    public function show(
        Request $request,
        #[SensitiveParameter]
        Instance $instance,
        DevelopmentHibernationPolicy $policy,
        HibernationWakeFailureStore $failures,
        ScheduleInstanceRuntimeWakeAction $schedule,
        RuntimeActivationPage $pages,
    ): Response {
        $caller = $request->user();
        $uri = $this->forwardedUri($request);

        if (! $caller instanceof Node) {
            return $pages->failed('Active WireGuard peer identity required.', $instance, $uri);
        }

        $instance->loadMissing('node');

        if (! $policy->appliesToInstance($instance)) {
            return $pages->failed("Instance [{$instance->name}] is not an app-dev development target.", $instance, $uri);
        }

        $retry = in_array('orbit-wake-retry=1', explode('&', (string) parse_url($uri, PHP_URL_QUERY)), true);
        if ($retry) {
            $failures->forget($instance->id);
        }
        $failure = $failures->get($instance->id);
        if ($failure === null) {
            $schedule->afterResponse($instance);
        }

        return $failure === null
            ? $pages->progress($instance, $uri)
            : $pages->failed($failure, $instance, $uri);
    }

    private function forwardedUri(Request $request): string
    {
        $uri = $request->headers->get('X-Forwarded-Uri');

        return is_string($uri) ? $uri : '/';
    }
}
