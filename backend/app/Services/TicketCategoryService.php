<?php

namespace App\Services;

use App\Models\TicketCategory;
use Illuminate\Pagination\LengthAwarePaginator;

class TicketCategoryService
{
    private const SORTABLE_COLUMNS = ['name', 'code', 'created_at'];

    /** @param array{search?: string|null, sort?: string, direction?: string, per_page?: int} $filters */
    public function paginate(array $filters = []): LengthAwarePaginator
    {
        $query = TicketCategory::query()->withCount('tickets');

        if (! empty($filters['search'])) {
            $search = trim((string) $filters['search']);
            $query->where(function ($q) use ($search): void {
                $q->whereLike('name', "%{$search}%")
                    ->orWhereLike('code', "%{$search}%");
            });
        }

        $requested = $filters['sort'] ?? 'name';
        $sort = in_array($requested, self::SORTABLE_COLUMNS, true) ? $requested : 'name';
        $direction = ($filters['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
        $query->orderBy($sort, $direction);

        return $query->paginate($this->perPage($filters['per_page'] ?? null))->withQueryString();
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): TicketCategory
    {
        return TicketCategory::create($data);
    }

    /** @param array<string, mixed> $data */
    public function update(TicketCategory $category, array $data): TicketCategory
    {
        $category->update($data);

        return $category->refresh();
    }

    public function delete(TicketCategory $category): void
    {
        if ($category->tickets()->exists()) {
            abort(409, 'Ticket category cannot be deleted because it is still in use');
        }

        $category->delete();
    }

    private function perPage(?int $requested): int
    {
        return max(1, min(100, (int) (is_numeric($requested) ? $requested : 15)));
    }
}
