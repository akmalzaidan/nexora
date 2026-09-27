<?php

namespace App\Services;

use App\Models\AssetCategory;
use Illuminate\Pagination\LengthAwarePaginator;

class AssetCategoryService
{
    private const SORTABLE_COLUMNS = ['name', 'code', 'created_at'];

    /** @param array{search?: string|null, sort?: string, direction?: string, per_page?: int} $filters */
    public function paginate(array $filters = []): LengthAwarePaginator
    {
        $query = AssetCategory::query()->withCount('assets');

        if (! empty($filters['search'])) {
            $search = trim((string) $filters['search']);
            $query->where(function ($q) use ($search): void {
                $q->whereLike('name', "%{$search}%")
                    ->orWhereLike('code', "%{$search}%");
            });
        }

        $sort = in_array($filters['sort'] ?? 'name', self::SORTABLE_COLUMNS, true)
            ? ($filters['sort'] ?? 'name')
            : 'name';
        $direction = ($filters['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
        $query->orderBy($sort, $direction);

        return $query->paginate($this->perPage($filters['per_page'] ?? null))->withQueryString();
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): AssetCategory
    {
        return AssetCategory::create($data);
    }

    /** @param array<string, mixed> $data */
    public function update(AssetCategory $category, array $data): AssetCategory
    {
        $category->update($data);

        return $category->refresh();
    }

    public function delete(AssetCategory $category): void
    {
        if ($category->assets()->exists()) {
            abort(409, 'Asset category cannot be deleted because it is still in use');
        }

        $category->delete();
    }

    private function perPage(?int $requested): int
    {
        return max(1, min(100, (int) (is_numeric($requested) ? $requested : 15)));
    }
}
