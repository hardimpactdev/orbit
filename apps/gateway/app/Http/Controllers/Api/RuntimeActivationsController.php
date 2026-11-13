<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Hibernation\ScheduleInstanceRuntimeWakeAction;
use App\Domain\Hibernation\DevelopmentHibernationPolicy;
use App\Domain\Hibernation\HibernationWakeFailureStore;
use App\Domain\Instances\Power\InstanceWake;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Middleware\RequireActiveWireGuardPeer;
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
        InstanceWake $wake,
    ): Response {
        $caller = $request->user();
        $uri = $this->forwardedUri($request);

        if (! $caller instanceof Node) {
            return $pages->failed('Active WireGuard peer identity required.', $instance, $uri);
        }

        $instance->loadMissing('node');
        $retry = in_array('orbit-wake-retry=1', explode('&', (string) parse_url($uri, PHP_URL_QUERY)), true);

        // A container Instance's site calls here only behind its asleep marker: start or join its one wake
        // (docs/reference/development-containers.md#sleep-and-wake). A wake that ended within this call (a frozen container's
        // resume) sends the visitor's request again: the marker is gone, so Caddy now serves the app.
        if ($instance->runtime()->exists()) {
            $answer = $wake->activate($instance, $retry, 'node:'.(RequireActiveWireGuardPeer::activePeer($request)->id ?? $instance->node_id));

            return match (true) {
                $answer === true => $pages->again($uri),
                $answer === null => $pages->progress($instance, $uri),
                default => $pages->failed($answer, $instance, $uri),
            };
        }

        if (! $policy->appliesToInstance($instance)) {
            return $pages->failed("Instance [{$instance->name}] is not an app-dev development target.", $instance, $uri);
        }

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
