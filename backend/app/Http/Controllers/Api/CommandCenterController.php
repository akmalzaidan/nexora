<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\CommandCenter\CommandCenterRequest;
use App\Services\CommandCenter\CommandCenterService;
use Illuminate\Http\JsonResponse;

/**
 * Command Center / operational intelligence (see DR-019).
 *
 * The controller only wires the validated request into the service and wraps the
 * payload in the standard envelope — no aggregation, no raw SQL, and no Eloquent
 * resource, because the snapshot is a typed aggregate payload. There is exactly
 * one action: the surface is read-only, and no other verb is routed to it.
 */
class CommandCenterController extends Controller
{
    public function __construct(private readonly CommandCenterService $commandCenter) {}

    public function __invoke(CommandCenterRequest $request): JsonResponse
    {
        return $this->success(
            data: $this->commandCenter->snapshotFor($request->user(), $request->limit()),
            message: 'Command Center snapshot',
        );
    }
}
