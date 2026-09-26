<?php

namespace App\Services;

use App\Models\Location;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Location domain logic: querying, create/update/delete. Controllers stay thin
 * and only wire requests through here to resources.
 */
class LocationService
{
    private const SORTABLE_COLUMNS = ['name', 'code', 'created_at'];

    /**
     * @param  array{search?: string|null, is_active?: bool, sort?: string, direction?: string, per_page?: int}  $filters
     * @return LengthAwarePaginator<Location>
     */
    public function paginate(array $filters = []): LengthAwarePaginator
    {
        $query = Location::query();

        if (! empty($filters['search'])) {
            $search = trim((string) $filters['search']);
            $query->where(function ($query) use ($search): void {
                $query->whereLike('name', "%{$search}%")
                    ->orWhereLike('code', "%{$search}%");
            });
        }

        if (isset($filters['is_active'])) {
            $query->where('is_active', $filters['is_active']);
        }

        $sort = in_array($filters['sort'] ?? 'name', self::SORTABLE_COLUMNS, true)
            ? $filters['sort']
            : 'name';
        $direction = ($filters['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
        $query->orderBy($sort, $direction);

        return $query->paginate($this->perPage($filters['per_page'] ?? null))->withQueryString();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Location
    {
        $data['is_active'] = (bool) ($data['is_active'] ?? true);

        return Location::create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Location $location, array $data): Location
    {
        $data = array_key_exists('is_active', $data)
            ? [...$data, 'is_active' => (bool) $data['is_active']]
            : $data;

        $location->update($data);

        return $location->refresh();
    }

    public function delete(Location $location): void
    {
        $inUse = $location->assets()->exists()
            || $location->warehouses()->exists()
            || $location->assetAssignments()->exists();

        if ($inUse) {
            abort(409, 'Location cannot be deleted because it is still in use');
        }

        $location->delete();
    }

    private function perPage(?int $requested): int
    {
        return max(1, min(100, (int) (is_numeric($requested) ? $requested : 15)));
    }
}
