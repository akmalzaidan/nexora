<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Audit\AuditLogIndexRequest;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use App\Services\AuditLogQuery;
use Illuminate\Http\JsonResponse;

/**
 * Read-only Audit Logs & Governance API (Phase 20A).
 *
 * Exactly two endpoints — list and detail — behind `auth:sanctum` +
 * `permission:view_audit_logs`. There is deliberately no store/update/destroy
 * method on this controller and no corresponding route: the audit trail is
 * append-only and written exclusively by AuditLogService from inside the
 * domain services, so any write verb on this resource is a 405 by
 * construction, not by filtering.
 */
class AuditLogController extends Controller
{
    public function __construct(private readonly AuditLogQuery $auditLogQuery) {}

    public function index(AuditLogIndexRequest $request): JsonResponse
    {
        $logs = $this->auditLogQuery->paginate($request->filters());

        return $this->success(
            data: [
                'items' => AuditLogResource::collection($logs->items()),
                'pagination' => [
                    'current_page' => $logs->currentPage(),
                    'per_page' => $logs->perPage(),
                    'total' => $logs->total(),
                    'last_page' => $logs->lastPage(),
                ],
            ],
            message: 'Audit logs retrieved successfully',
        );
    }

    public function show(AuditLog $auditLog): JsonResponse
    {
        $auditLog->load('user');

        return $this->success(
            data: new AuditLogResource($auditLog),
            message: 'Audit log retrieved successfully',
        );
    }
}
