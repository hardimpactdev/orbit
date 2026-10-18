<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Gateway\ExactRequestBody;
use Closure;
use Illuminate\Container\Attributes\Tag;
use Illuminate\Http\Middleware\ValidatePostSize;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** MCP envelopes and contributed exact bodies ({@see ExactRequestBody}) enforce their own bounded limits after authorization. */
final class ValidateRequestBodySize extends ValidatePostSize
{
    /** The MCP envelopes, which the MCP server bounds after active-peer authorization. */
    private const array MCP_PATHS = ['mcp', 'mcp/search'];

    private const string EXACT_BODY = 'orbit.exact_body';

    /** @param iterable<ExactRequestBody> $bodies */
    public function __construct(
        #[Tag(ExactRequestBody::class)] private readonly iterable $bodies,
    ) {}

    /** @param Closure(Request): Response $next */
    public function handle($request, Closure $next): Response
    {
        if (self::keepsExactBody($request)) {
            return $next($request);
        }
        $response = parent::handle($request, $next);
        if (! $response instanceof Response) {
            throw new \LogicException('HTTP middleware must return a response.');
        }

        return $response;
    }

    /**
     * Whether the request skips the PHP size check, string trimming, and empty-string conversion. The answer is kept on
     * the request, so the three middleware resolve the matchers once.
     */
    public static function keepsExactBody(Request $request): bool
    {
        $exact = $request->attributes->get(self::EXACT_BODY);
        if (! is_bool($exact)) {
            $exact = app(self::class)->matches($request->path());
            $request->attributes->set(self::EXACT_BODY, $exact);
        }

        return $exact;
    }

    private function matches(string $path): bool
    {
        if (in_array($path, self::MCP_PATHS, true)) {
            return true;
        }
        foreach ($this->bodies as $body) {
            if ($body->matches($path)) {
                return true;
            }
        }

        return false;
    }
}
