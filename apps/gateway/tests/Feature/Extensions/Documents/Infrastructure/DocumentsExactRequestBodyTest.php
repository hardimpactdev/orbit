<?php

declare(strict_types=1);

use App\Http\Middleware\ValidateRequestBodySize;
use Illuminate\Http\Request;

it('keeps only document request bodies exact', function (string $path, bool $exact): void {
    expect(ValidateRequestBodySize::keepsExactBody(Request::create($path, 'POST')))->toBe($exact);
})->with([
    ['/api/v1/projects/7/documents', true],
    ['/api/v1/projects/007/documents/3/content', true],
    ['/api/v1/projects/7/documents/search', true],
    ['/api/v1/projects/7/documentsx', false],
    ['/api/v1/projects/x/documents', false],
    ['/api/v1/project-document-storage', false],
    ['/api/v1/projects/7', false],
]);
