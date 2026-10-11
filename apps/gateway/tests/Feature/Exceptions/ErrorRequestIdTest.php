<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleAssignmentException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Renders the exception without the middleware stack, so the response header shows what the
 * render callback chose rather than what EnsureRequestId sets afterwards.
 */
function render_error_request_id(Throwable $exception, ?string $attribute, ?string $header): string
{
    $request = Request::create('/api/v1/testing/error', 'GET', server: $header === null ? [] : ['HTTP_X_ORBIT_REQUEST_ID' => $header]);

    if ($attribute !== null) {
        $request->attributes->set('orbit.request_id', $attribute);
    }

    $response = app(ExceptionHandler::class)->render($request, $exception);

    return (string) $response->headers->get('X-Orbit-Request-Id');
}

describe('error response request ID', function (): void {
    it('uses the request ID the middleware assigned', function (Throwable $exception): void {
        expect(render_error_request_id($exception, 'assigned-id', 'sent-id'))->toBe('assigned-id');
    })->with('request ID error renderers');

    it('falls back to the sent header when no request ID was assigned', function (Throwable $exception, ?string $attribute): void {
        expect(render_error_request_id($exception, $attribute, 'sent-id'))->toBe('sent-id');
    })->with('request ID error renderers')->with(['missing' => [null], 'empty' => ['']]);

    it('sends an empty request ID when neither is present', function (Throwable $exception): void {
        expect(render_error_request_id($exception, null, null))->toBe('');
    })->with('request ID error renderers');
});

dataset('request ID error renderers', [
    'domain refusal' => [fn (): Throwable => new RoleAssignmentException('The role is assigned elsewhere.')],
    'not found' => [fn (): Throwable => new NotFoundHttpException],
    'unhandled' => [fn (): Throwable => new RuntimeException('boom')],
]);
