<?php

namespace App\Services;

use App\Models\AssetAssignment;
use App\Models\MaintenanceRequest;
use App\Models\Role;
use App\Models\StockMovement;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\User;
use App\Support\Audit\AuditResourceType;
use App\Support\Audit\SensitiveValueFilter;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * User Management domain logic for administrators.
 *
 * Authorization stays on top (route permission middleware); this service
 * implements the documented administration policies:
 *
 *  - Role assignment: a non-super-admin may never assign the super_admin role.
 *  - Self-protection: a user cannot change their own role, deactivate
 *    themselves, or delete their own account.
 *  - Last admin guard: while a user is the last active super admin they cannot
 *    be deactivated, demoted, or deleted — so the system can never be left
 *    without a super admin.
 *  - Delete conflicts: users referenced by rows whose foreign keys would block
 *    deletion are rejected with 409. Non-blocking references that the schema
 *    nulls out (e.g. departments.manager_id) do not block deletion.
 */
class UserManagementService
{
    /**
     * Governance fields recorded in the audit trail. `password` is
     * deliberately absent: its PRESENCE may be audited, never its value.
     */
    private const AUDITABLE_FIELDS = ['name', 'email', 'role_id', 'department_id', 'is_active'];

    private const SORTABLE_COLUMNS = ['name', 'email', 'created_at'];

    public function __construct(private readonly AuditLogService $auditLogs) {}

    private const DEFAULT_PER_PAGE = 15;

    private const MAX_PER_PAGE = 100;

    /**
     * @param  array{search?: string|null, is_active?: bool|null, role_id?: int|string|null, department_id?: int|string|null, sort?: string|null, direction?: string|null, per_page?: int|null}  $filters
     * @return LengthAwarePaginator<User>
     */
    public function paginate(array $filters = []): LengthAwarePaginator
    {
        $query = User::query()->with(['role', 'department']);

        if (! empty($filters['search'])) {
            $search = trim((string) $filters['search']);
            $query->where(static function ($query) use ($search): void {
                $query->whereLike('name', "%{$search}%")
                    ->orWhereLike('email', "%{$search}%");
            });
        }

        if (isset($filters['is_active'])) {
            $query->where('is_active', $filters['is_active']);
        }

        if (isset($filters['role_id']) && $filters['role_id'] !== '') {
            $query->where('role_id', (int) $filters['role_id']);
        }

        if (isset($filters['department_id']) && $filters['department_id'] !== '') {
            $query->where('department_id', (int) $filters['department_id']);
        }

        $requestedSort = $filters['sort'] ?? 'name';
        $sort = in_array($requestedSort, self::SORTABLE_COLUMNS, true) ? $requestedSort : 'name';
        $direction = ($filters['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
        $query->orderBy($sort, $direction);

        return $query->paginate($this->perPage($filters['per_page'] ?? null))->withQueryString();
    }

    public function find(User $user): User
    {
        return $user->load(['role', 'department']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?User $actor = null): User
    {
        $actor ??= $this->actor();

        $this->assertRoleAssignable((int) $data['role_id'], $actor);

        $user = User::create($this->normalize($data));
        $user->load(['role', 'department']);

        $this->auditLogs->recordCreated(
            AuditResourceType::USER,
            $user->id,
            $actor,
            "User {$user->email} created with role {$user->role?->slug}",
            newValues: $this->auditableAttributes($user),
        );

        return $user;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(User $user, array $data, ?User $actor = null): User
    {
        $actor ??= $this->actor();

        $this->assertSelfRoleChange($user, $data, $actor);
        $this->assertSelfDeactivation($user, $data, $actor);

        $guarded = $this->isLastActiveSuperAdmin($user);

        if ($guarded) {
            if (array_key_exists('is_active', $data) && ! $this->toBool($data['is_active'])) {
                abort(403, 'Cannot deactivate the last active super admin');
            }

            if (array_key_exists('role_id', $data) && (int) $data['role_id'] !== $this->superAdminRoleId()) {
                abort(403, 'Cannot change the role of the last active super admin');
            }
        }

        if (array_key_exists('role_id', $data)) {
            $this->assertRoleAssignable((int) $data['role_id'], $actor);
        }

        $changes = $this->auditedChanges($user, $this->normalizeForUpdate($data));

        $user->update($this->normalizeForUpdate($data));

        if ($changes['new_values'] !== []) {
            $this->auditLogs->recordUpdated(
                AuditResourceType::USER,
                $user->id,
                $actor,
                'User '.$user->email.' updated ('.implode(', ', array_keys($changes['new_values'])).')',
                oldValues: $changes['old_values'],
                newValues: $changes['new_values'],
            );
        }

        return $user->load(['role', 'department']);
    }

    public function delete(User $user, ?User $actor = null): void
    {
        $actor ??= $this->actor();

        if ($user->is($actor)) {
            abort(409, 'You cannot delete your own account');
        }

        if ($this->isLastActiveSuperAdmin($user)) {
            abort(409, 'Cannot delete the last active super admin');
        }

        if ($this->hasBlockingReferences($user)) {
            abort(409, 'User cannot be deleted because it is still in use');
        }

        $audited = $this->auditableAttributes($user);

        $user->delete();

        $this->auditLogs->recordDeleted(
            AuditResourceType::USER,
            $user->id,
            $actor,
            "User {$user->email} deleted",
            oldValues: $audited,
        );
    }

    /**
     * Governance-safe projection of a user for the audit trail: only the
     * allowlisted fields, with the role expressed by its stable slug. Password
     * values never appear here.
     *
     * @return array<string, mixed>
     */
    private function auditableAttributes(User $user): array
    {
        $attributes = [];

        foreach (self::AUDITABLE_FIELDS as $field) {
            $attributes[$field] = $user->getAttribute($field);
        }

        $attributes['role_id'] = $user->role?->slug ?? $user->role_id;

        return $attributes;
    }

    /**
     * Compute the auditable before/after delta for the fields about to change.
     *
     * @param  array<string, mixed>  $data
     * @return array{old_values: array<string, mixed>, new_values: array<string, mixed>}
     */
    private function auditedChanges(User $user, array $data): array
    {
        $old = [];
        $new = [];

        foreach ($data as $field => $value) {
            if (! in_array($field, self::AUDITABLE_FIELDS, true)) {
                continue;
            }

            if ($user->getAttribute($field) === $value) {
                continue;
            }

            $old[$field] = $field === 'role_id' ? ($user->role?->slug ?? $user->role_id) : $user->getAttribute($field);
            $new[$field] = $field === 'role_id' ? (Role::find($value)?->slug ?? $value) : $value;
        }

        return ['old_values' => SensitiveValueFilter::clean($old), 'new_values' => SensitiveValueFilter::clean($new)];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function normalize(array $data): array
    {
        return [
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'role_id' => (int) $data['role_id'],
            'department_id' => isset($data['department_id']) && $data['department_id'] !== null
                ? (int) $data['department_id']
                : null,
            'is_active' => $this->toBool($data['is_active'] ?? true),
        ];
    }

    /**
     * Only the explicitly-present keys are applied; absent fields are left
     * untouched. is_active and role_id are normalized to DB-safe values.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizeForUpdate(array $data): array
    {
        $attributes = [];

        foreach (['name', 'email', 'password'] as $field) {
            if (array_key_exists($field, $data)) {
                $attributes[$field] = $data[$field];
            }
        }

        if (array_key_exists('role_id', $data)) {
            $attributes['role_id'] = (int) $data['role_id'];
        }

        if (array_key_exists('department_id', $data)) {
            $attributes['department_id'] = $data['department_id'] !== null
                ? (int) $data['department_id']
                : null;
        }

        if (array_key_exists('is_active', $data)) {
            $attributes['is_active'] = $this->toBool($data['is_active']);
        }

        return $attributes;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertSelfRoleChange(User $user, array $data, User $actor): void
    {
        if ($user->is($actor)
            && array_key_exists('role_id', $data)
            && (int) $data['role_id'] !== (int) $user->role_id) {
            abort(403, 'You cannot change your own role');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertSelfDeactivation(User $user, array $data, User $actor): void
    {
        if ($user->is($actor)
            && array_key_exists('is_active', $data)
            && ! $this->toBool($data['is_active'])) {
            abort(403, 'You cannot deactivate your own account');
        }
    }

    private function assertRoleAssignable(int $roleId, User $actor): void
    {
        if ($roleId === $this->superAdminRoleId() && ! $actor->isSuperAdmin()) {
            abort(403, 'Only a super admin can assign the super_admin role');
        }
    }

    private function isLastActiveSuperAdmin(User $user): bool
    {
        return $user->is_active
            && (int) $user->role_id === $this->superAdminRoleId()
            && ! User::query()
                ->where('role_id', $this->superAdminRoleId())
                ->where('is_active', true)
                ->where('id', '!=', $user->id)
                ->exists();
    }

    /**
     * Mirrors the RESTRICT foreign keys that reference users so we return a
     * clean 409 instead of letting the database raise an integrity error.
     *
     * `nullOnDelete` references (department manager, asset current holder,
     * asset histories, audit logs) intentionally do not block deletion.
     * `notifications.user_id` cascades, so deleting a user also removes their
     * notification inbox — that is deliberate (an inbox is per-user session
     * state, not history) and it never blocks deletion either.
     */
    private function hasBlockingReferences(User $user): bool
    {
        return AssetAssignment::query()
            ->where('user_id', $user->id)
            ->orWhere('requested_by', $user->id)
            ->exists()
            || Ticket::where('requester_id', $user->id)->exists()
            || TicketComment::where('user_id', $user->id)->exists()
            || StockMovement::where('performed_by', $user->id)->exists()
            || MaintenanceRequest::where('requested_by', $user->id)->exists();
    }

    private function superAdminRoleId(): int
    {
        return (int) Role::where('slug', 'super_admin')->value('id');
    }

    private function toBool(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    private function actor(): ?User
    {
        return auth()->user();
    }

    private function perPage(mixed $requested): int
    {
        if (is_numeric($requested)) {
            return max(1, min(self::MAX_PER_PAGE, (int) $requested));
        }

        return self::DEFAULT_PER_PAGE;
    }
}
