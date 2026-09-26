<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Report\ReportRequest;
use App\Services\Reports\ReportService;
use Illuminate\Http\JsonResponse;

/**
 * Read-only operational reports (see DR-018).
 *
 * The controller only wires the validated request into the report service and
 * wraps the aggregate payload in the standard envelope — no aggregation, no
 * raw SQL, and no Eloquent resource, because a report is a typed aggregate
 * payload rather than a serialized model.
 */
class ReportController extends Controller
{
    public function __construct(private readonly ReportService $reports) {}

    public function overview(): JsonResponse
    {
        return $this->success(
            data: $this->reports->overview(),
            message: 'Operational overview',
        );
    }

    public function assets(ReportRequest $request): JsonResponse
    {
        return $this->success(
            data: $this->reports->assets($request->period()),
            message: 'Asset report',
        );
    }

    public function inventory(ReportRequest $request): JsonResponse
    {
        return $this->success(
            data: $this->reports->inventory($request->period(), $request->limit()),
            message: 'Inventory report',
        );
    }

    public function tickets(ReportRequest $request): JsonResponse
    {
        return $this->success(
            data: $this->reports->tickets($request->period()),
            message: 'Ticket report',
        );
    }

    public function maintenance(ReportRequest $request): JsonResponse
    {
        return $this->success(
            data: $this->reports->maintenance($request->period(), $request->limit()),
            message: 'Maintenance report',
        );
    }
}
