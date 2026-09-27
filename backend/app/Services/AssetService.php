<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\User;
use App\Support\Audit\AuditResourceType;
use Illuminate\Pagination\LengthAwarePaginator;

class AssetService
{
    /**
     * Governance fields recorded in the audit trail for asset mutations.
     */
    private const AUDITABLE_FIELDS = [
        'asset_code',
        'name',
        'status',
        'condition',
        'asset_category_id',
        'location_id',
    ];

    private const SORTABLE_COLUMNS = ['name', 'asset_code', 'created_at'];

    public function __construct(private readonly AuditLogService $auditLogs) {}

    /** @param array{search?: string|null, asset_category_id?: int|null, location_id?: int|null, status?: string|null, sort?: string, direction?: string, per_page?: int} $filters */
    public function paginate(array $filters = []): LengthAwarePaginator
    {
        $query = Asset::query()
            ->with(['category', 'location'])
            ->withoutTrashed();

        if (! empty($filters['search'])) {
            $search = trim((string) $filters['search']);
            $query->where(function ($q) use ($search): void {
                $q->whereLike('asset_code', "%{$search}%")
                    ->orWhereLike('name', "%{$search}%")
                    ->orWhereLike('serial_number', "%{$search}%");
            });
        }

        if (! empty($filters['asset_category_id'])) {
            $query->where('asset_category_id', (int) $filters['asset_category_id']);
        }

        if (! empty($filters['location_id'])) {
            $query->where('location_id', (int) $filters['location_id']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', (string) $filters['status']);
        }

        $sort = in_array($filters['sort'] ?? 'name', self::SORTABLE_COLUMNS, true)
            ? ($filters['sort'] ?? 'name')
            : 'name';
        $direction = ($filters['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
        $query->orderBy($sort, $direction);

        return $query->paginate($this->perPage($filters['per_page'] ?? null))->withQueryString();
    }

    /** @param array<string, mixed> $data */
    public function create(array $data, ?User $actor = null): Asset
    {
        $asset = Asset::create($data);

        $this->auditLogs->recordCreated(
            AuditResourceType::ASSET,
            $asset->id,
            $actor ?? auth()->user(),
            "Asset {$asset->asset_code} created",
            newValues: $this->auditedAttributes($asset),
        );

        return $asset;
    }

    /** @param array<string, mixed> $data */
    public function update(Asset $asset, array $data, ?User $actor = null): Asset
    {
        $changes = $this->auditedChanges($asset, $data);

        $asset->update($data);

        if ($changes !== []) {
            $this->auditLogs->recordUpdated(
                AuditResourceType::ASSET,
                $asset->id,
                $actor ?? auth()->user(),
                "Asset {$asset->asset_code} updated",
                oldValues: $changes['old_values'],
                newValues: $changes['new_values'],
            );
        }

        return $asset->refresh();
    }

    public function delete(Asset $asset, ?User $actor = null): void
    {
        if ($asset->assignments()->exists()) {
            abort(409, 'Asset cannot be deleted because it is still in use');
        }

        if ($asset->histories()->exists()) {
            abort(409, 'Asset cannot be deleted because it has historical records');
        }

        $audited = $this->auditedAttributes($asset);

        $asset->delete();

        $this->auditLogs->recordDeleted(
            AuditResourceType::ASSET,
            $asset->id,
            $actor ?? auth()->user(),
            "Asset {$asset->asset_code} deleted",
            oldValues: $audited,
        );
    }

    /**
     * Governance-safe projection of an asset for the audit trail.
     *
     * @return array<string, mixed>
     */
    private function auditedAttributes(Asset $asset): array
    {
        $attributes = [];

        foreach (self::AUDITABLE_FIELDS as $field) {
            $attributes[$field] = $asset->getAttribute($field);
        }

        return $attributes;
    }

    /**
     * Auditable before/after delta for the fields about to change.
     *
     * @param  array<string, mixed>  $data
     * @return array{old_values: array<string, mixed>, new_values: array<string, mixed>}|array<empty, empty>
     */
    private function auditedChanges(Asset $asset, array $data): array
    {
        $old = [];
        $new = [];

        foreach ($data as $field => $value) {
            if (! in_array($field, self::AUDITABLE_FIELDS, true)) {
                continue;
            }

            if ($asset->getAttribute($field) === $value) {
                continue;
            }

            $old[$field] = $asset->getAttribute($field);
            $new[$field] = $value;
        }

        return $old === [] ? [] : ['old_values' => $old, 'new_values' => $new];
    }

    private function perPage(?int $requested): int
    {
        return max(1, min(100, (int) (is_numeric($requested) ? $requested : 15)));
    }
}
