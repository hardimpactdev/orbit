<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Certificates\SignDirectCertificatesAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Operations\SignDirectCertificatesRequest;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Http\JsonResponse;

final class DirectCertificatesController extends Controller
{
    public function sign(SignDirectCertificatesRequest $request, Operation $operation, SignDirectCertificatesAction $signer): JsonResponse
    {
        $data = $request->validated();

        return response()->json(['data' => ['certificates' => $signer->handle($operation, $request->peerNodeId(),
            ValidatedData::string($data['run_id']), $request->certificateRequests(), ($data['restoring'] ?? false) === true)],
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')]])->header('Cache-Control', 'private, no-store');
    }
}
