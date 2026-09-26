<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\Audit\AuditAction;
use App\Support\Audit\SensitiveValueFilter;
use Illuminate\Http\Request;

/**
 * Write side of the governance audit trail (Phase 20A).
 *
 * `audit_logs` is append-only (DR-003, DR-020): this service is the ONLY
 * application-level writer and exposes no update or delete path. It answers
 * four questions for every governance-relevant mutation — WHO (actor resolved
 * server-side), WHAT (action from the controlled vocabulary), WHICH RESOURCE
 * (type from the controlled vocabulary + id) and WHEN/CONTEXT (timestamp,
 * concise factual description, safe old/new values).
 *
 * Design rules enforced here:
 *  - The actor is never accepted from client input; services pass the
 *    authenticated user (or null for system operations — the schema supports
 *    it, no fake user is invented; the FK is nullOnDelete so rows survive
 *    user deletion).
 *  - Nothing sensitive is stored: old/new values pass through
 *    SensitiveValueFilter and only safe governance metadata is kept.
 *  - The insert is a single direct write with a known actor id — no extra
 *    lookups, no observer, no event bus (DR-020 performance budget).
 *  - Callers invoke this ONLY after the business mutation succeeded and inside
 *    the same DB::transaction where one exists, so a failed or rolled-back
 *    mutation can never leave a misleading audit row.
 *  - Request context (IP, user agent) is captured from the current request
 *    when the application is serving HTTP; console/seed runs record null.
 */
class AuditLogService
{
    /**
     * Record a governance audit event.
     *
     * @param  string  $action  One of App\Support\Audit\AuditAction.
     * @param  string  $resourceType  One of App\Support\Audit\AuditResourceType.
     * @param  int|string|null  $resourceId  Identifier of the affected resource.
     * @param  User|null  $actor  Authenticated actor resolved server-side; null for system operations.
     * @param  string|null  $description  Concise factual fact, e.g. "Ticket status changed from OPEN to IN_PROGRESS".
     * @param  array<string, mixed>  $oldValues  Safe allowlisted before-values (sensitive keys stripped).
     * @param  array<string, mixed>  $newValues  Safe allowlisted after-values (sensitive keys stripped).
     */
    public function record(
        string $action,
        string $resourceType,
        int|string|null $resourceId = null,
        ?User $actor = null,
        ?string $description = null,
        array $oldValues = [],
        array $newValues = [],
    ): void {
        $request = $this->currentRequest();

        AuditLog::create([
            'user_id' => $actor?->id,
            'action' => $action,
            'entity_type' => $resourceType,
            'entity_id' => $resourceId !== null ? (int) $resourceId : null,
            'description' => $description,
            'old_values' => SensitiveValueFilter::clean($oldValues),
            'new_values' => SensitiveValueFilter::clean($newValues),
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'created_at' => now(),
        ]);
    }

    /**
     * Record a successful resource creation.
     *
     * @param  array<string, mixed>  $newValues
     */
    public function recordCreated(
        string $resourceType,
        int|string $resourceId,
        ?User $actor,
        string $description,
        array $newValues = [],
    ): void {
        $this->record(
            action: AuditAction::CREATED,
            resourceType: $resourceType,
            resourceId: $resourceId,
            actor: $actor,
            description: $description,
            newValues: $newValues,
        );
    }

    /**
     * Record a successful resource update.
     *
     * @param  array<string, mixed>  $oldValues
     * @param  array<string, mixed>  $newValues
     */
    public function recordUpdated(
        string $resourceType,
        int|string $resourceId,
        ?User $actor,
        string $description,
        array $oldValues = [],
        array $newValues = [],
    ): void {
        $this->record(
            action: AuditAction::UPDATED,
            resourceType: $resourceType,
            resourceId: $resourceId,
            actor: $actor,
            description: $description,
            oldValues: $oldValues,
            newValues: $newValues,
        );
    }

    /**
     * Record a successful resource deletion.
     *
     * @param  array<string, mixed>  $oldValues
     */
    public function recordDeleted(
        string $resourceType,
        int|string $resourceId,
        ?User $actor,
        string $description,
        array $oldValues = [],
    ): void {
        $this->record(
            action: AuditAction::DELETED,
            resourceType: $resourceType,
            resourceId: $resourceId,
            actor: $actor,
            description: $description,
            oldValues: $oldValues,
        );
    }

    /**
     * Record a lifecycle event that is not a plain CRUD mutation (assignment,
     * return, status transition, registration, authentication).
     *
     * @param  array<string, mixed>  $oldValues
     * @param  array<string, mixed>  $newValues
     */
    public function recordEvent(
        string $action,
        string $resourceType,
        int|string|null $resourceId,
        ?User $actor,
        string $description,
        array $oldValues = [],
        array $newValues = [],
    ): void {
        $this->record(
            action: $action,
            resourceType: $resourceType,
            resourceId: $resourceId,
            actor: $actor,
            description: $description,
            oldValues: $oldValues,
            newValues: $newValues,
        );
    }

    /**
     * The HTTP request serving the current mutation, or null outside HTTP
     * (seeding, queued jobs, tests that call services directly).
     */
    private function currentRequest(): ?Request
    {
        $request = app()->bound('request') ? app('request') : null;

        return $request instanceof Request ? $request : null;
    }
}
