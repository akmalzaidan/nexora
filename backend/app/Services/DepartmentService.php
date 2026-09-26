<?php

namespace App\Services;

use App\Models\Department;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Department domain logic: querying, create/update/delete. Controllers stay
 * thin and only wire requests through here to resources.
 */
class DepartmentService
{
    private const SORTABLE_COLUMNS = ['name', 'code', 'created_at'];

    /**
     * @param  array{search?: string|null, is_active?: bool, sort?: string, direction?: string, per_page?: int}  $filters
     * @return LengthAwarePaginator<Department>
     */
    public function paginate(array $filters = []): LengthAwarePaginator
    {
        $query = Department::query()
            ->with('manager')
            ->withCount('users');

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
    public function create(array $data): Department
    {
        $department = Department::create($this->normalize($data));

        return $this->loadDetails($department);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Department $department, array $data): Department
    {
        $department->update($this->normalizeForUpdate($department, $data));

        return $this->loadDetails($department);
    }

    public function delete(Department $department): void
    {
        if ($department->users()->exists()) {
            abort(409, 'Department cannot be deleted because it is still in use');
        }

        $department->delete();
    }

    private function loadDetails(Department $department): Department
    {
        return $department->load('manager')->loadCount('users');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{name: string, code: string, description?: string|null, manager_id: int|null, is_active: bool}
     */
    private function normalize(array $data): array
    {
        $data['is_active'] = (bool) ($data['is_active'] ?? true);
        $data['manager_id'] = isset($data['manager_id']) && $data['manager_id'] !== null
            ? (int) $data['manager_id']
            : null;

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{name: string, code: string, description: string|null, manager_id: int|null, is_active: bool}
     */
    private function normalizeForUpdate(Department $department, array $data): array
    {
        return [
            'name' => $data['name'],
            'code' => $data['code'],
            'description' => array_key_exists('description', $data)
                ? ($data['description'] ?? null)
                : $department->description,
            'manager_id' => array_key_exists('manager_id', $data)
                ? ($data['manager_id'] !== null ? (int) $data['manager_id'] : null)
                : $department->manager_id,
            'is_active' => array_key_exists('is_active', $data)
                ? (bool) $data['is_active']
                : $department->is_active,
        ];
    }

    private function perPage(?int $requested): int
    {
        return max(1, min(100, (int) (is_numeric($requested) ? $requested : 15)));
    }
}
