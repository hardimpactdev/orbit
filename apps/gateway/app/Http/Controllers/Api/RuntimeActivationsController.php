<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Hibernation\ActivateInstanceRuntimeAction;
use App\Domain\Hibernation\HibernationException;
use App\Domain\Instances\Power\InstanceWake;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Middleware\RequireActiveWireGuardPeer;
use App\Models\Instance;
use App\Models\Node;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use SensitiveParameter;
use Throwable;

/**
 * Caddy's forward_auth for a sleeping Instance. The request is held while the Instance wakes. Caddy returns any non-2xx answer to
 * the visitor as it is: a woken Instance answers a 307 to the visitor's own URI, which the browser repeats with the same method and
 * body and the awake app answers; a failed wake answers a plain 503 with a short text. A 2xx would continue the request only where
 * the route runs the app after forward_auth; routes that hold activation and the app in separate `handle` blocks end it there.
 */
#[RequiresNodeAccess(ServingNode::InstanceHost)]
final class RuntimeActivationsController extends Controller
{
    public function show(
        Request $request,
        #[SensitiveParameter]
        Instance $instance,
        ActivateInstanceRuntimeAction $activate,
        InstanceWake $wake,
    ): Response {
        if (! $request->user() instanceof Node) {
            return $this->failed('Active WireGuard peer identity required.');
        }

        $instance->loadMissing('node');

        if ($instance->runtime()->exists()) {
            $failure = $wake->activate($instance, 'node:'.(RequireActiveWireGuardPeer::activePeer($request)->id ?? $instance->node_id));

            return $failure === null ? $this->ready($request) : $this->failed($failure);
        }

        try {
            $activate->execute($instance);
        } catch (HibernationException $exception) {
            return $this->failed($exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return $this->failed('The runtime wake failed. Check the Gateway log before retrying.');
        }

        return $this->ready($request);
    }

    /** Only a path on the visitor's own host: a forwarded URI that names another origin goes to `/`. */
    private function ready(Request $request): Response
    {
        $uri = $request->headers->get('X-Forwarded-Uri');
        $location = is_string($uri) && str_starts_with($uri, '/') && ! str_starts_with($uri, '//') && ! str_starts_with($uri, '/\\') ? $uri : '/';

        return response('', 307)
            ->header('Location', $location)
            ->header('Cache-Control', 'no-store');
    }

    private function failed(string $message): Response
    {
        return response(trim($message)."\n", 503)
            ->header('Content-Type', 'text/plain; charset=UTF-8')
            ->header('Cache-Control', 'no-store, private')
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
