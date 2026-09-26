<?php

namespace App\Services;

use App\Models\Item;
use App\Models\MaintenancePart;
use App\Models\MaintenanceRecord;
use App\Models\MaintenanceRequest;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Maintenance part usage domain logic.
 *
 * A maintenance part is historical usage data: it records which inventory item
 * (and how much of it) was consumed for a work order. Part rows are append-only
 * — there are deliberately no update/delete endpoints.
 *
 * Inventory integration: creating a maintenance part does NOT move stock. The
 * Phase 03 schema links a part to an item but not to a warehouse or stock
 * journal, so there is no unambiguous movement to derive; inventory remains the
 * sole source of truth. Stock consumption for maintenance is recorded through
 * the existing Inventory API (POST /stock-movements with reference_type
 * "maintenance"), which maintains the DR-014 balance invariants. This keeps a
 * single stock ledger instead of a second, independent maintenance counter.
 */
class MaintenancePartService
{
    private const EAGER_LOAD = ['item'];

    /**
     * @param  array{per_page?: int|null}  $filters
     * @return LengthAwarePaginator<MaintenancePart>
     */
    public function paginate(MaintenanceRecord $maintenanceRecord, array $filters, User $actor)
    {
        $this->assertCanView($maintenanceRecord, $actor);

        return $maintenanceRecord->parts()
            ->with(self::EAGER_LOAD)
            ->oldest('created_at')
            ->oldest('id')
            ->paginate($this->perPage($filters['per_page'] ?? null))
            ->withQueryString();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, MaintenanceRecord $maintenanceRecord, User $actor): MaintenancePart
    {
        return DB::transaction(function () use ($data, $maintenanceRecord, $actor): MaintenancePart {
            $this->assertCanView($maintenanceRecord, $actor);

            $requestStatus = (string) $maintenanceRecord->request()->value('status');

            if (! in_array($requestStatus, [MaintenanceRequest::STATUS_APPROVED, MaintenanceRequest::STATUS_IN_PROGRESS], true)) {
                throw new UnprocessableEntityHttpException('Parts cannot be added once the work is complete or cancelled.');
            }

            $item = Item::query()->withoutTrashed()->find((int) $data['item_id']);

            if (! $item) {
                throw new UnprocessableEntityHttpException('The selected item does not exist.');
            }

            if (! $item->is_active) {
                throw new UnprocessableEntityHttpException('Cannot use an inactive inventory item for maintenance parts.');
            }

            return MaintenancePart::create([
                'maintenance_record_id' => $maintenanceRecord->id,
                'item_id' => $item->id,
                'quantity' => (int) $data['quantity'],
            ])->load(self::EAGER_LOAD);
        });
    }

    private function canManage(?User $actor): bool
    {
        return $actor?->hasPermission('manage_maintenance') ?? false;
    }

    private function assertCanView(MaintenanceRecord $maintenanceRecord, User $actor): void
    {
        if ($this->canManage($actor)) {
            return;
        }

        $requesterId = (int) $maintenanceRecord->request()->value('requested_by');

        if ($requesterId !== (int) $actor->id) {
            abort(403, 'You do not have access to this maintenance record');
        }
    }

    private function perPage(mixed $requested): int
    {
        return max(1, min(100, (int) (is_numeric($requested) ? $requested : 15)));
    }
}
