<?php

declare(strict_types=1);

use App\Domain\Gateway\ExactRequestBody;
use App\Http\Middleware\ValidateRequestBodySize;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    Route::post('_body/{kind}', static fn (Request $request): array => ['name' => $request->input('name'), 'empty' => $request->input('empty')]);
    $this->resolved = 0;
    $this->app->bind('test.exact-request-body', function (): ExactRequestBody {
        $this->resolved++;

        return new class implements ExactRequestBody
        {
            public function matches(string $path): bool
            {
                return $path === '_body/exact';
            }
        };
    });
});

it('keeps a contributed path exact and checks every other body against the PHP limit', function (): void {
    $this->app->tag(['test.exact-request-body'], ExactRequestBody::class);
    $body = '{"name":" padded ","empty":""}';
    $oversized = ['CONTENT_TYPE' => 'application/json', 'CONTENT_LENGTH' => PHP_INT_MAX];

    $this->call('POST', '/_body/exact', server: $oversized, content: $body)
        ->assertOk()->assertExactJson(['name' => ' padded ', 'empty' => '']);
    $this->call('POST', '/_body/trimmed', server: $oversized, content: $body)->assertStatus(413);
    $this->call('POST', '/_body/trimmed', server: ['CONTENT_TYPE' => 'application/json'], content: $body)
        ->assertOk()->assertExactJson(['name' => 'padded', 'empty' => null]);
});

it('resolves the matchers once per request for the size check, trimming, and empty-string conversion', function (): void {
    $this->app->tag(['test.exact-request-body'], ExactRequestBody::class);

    $this->call('POST', '/_body/trimmed', server: ['CONTENT_TYPE' => 'application/json'], content: '{"name":" padded "}')->assertOk();

    expect($this->resolved)->toBe(1);
});

it('keeps the MCP envelopes exact without a contribution', function (string $path, bool $exact): void {
    expect(ValidateRequestBodySize::keepsExactBody(Request::create($path, 'POST')))->toBe($exact);
})->with([
    ['/mcp', true],
    ['/mcp/search', true],
    ['/mcp/other', false],
    ['/_body/exact', false],
]);
