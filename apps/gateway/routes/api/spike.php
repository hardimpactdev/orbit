<?php

declare(strict_types=1);

use App\Http\Middleware\RequireActiveWireGuardPeer;
use Illuminate\Support\Facades\Route;

// SPIKE: content by hash for executor primitives. Only an active fleet Node may fetch; the executor checks the hash
// that the signed order carries, so the bytes need no further authentication.
Route::middleware([RequireActiveWireGuardPeer::class])->get('spike/blobs/{sha}', function (string $sha) {
    abort_unless(preg_match('/\A[0-9a-f]{64}\z/', $sha) === 1, 404);
    $path = storage_path("app/spike-blobs/{$sha}");
    abort_unless(is_file($path), 404);

    return response()->file($path, ['Content-Type' => 'application/octet-stream', 'Cache-Control' => 'private, max-age=3600']);
})->name('spike:blob');
