<?php

namespace Database\Seeders;

use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\Item;
use App\Models\MaintenancePart;
use App\Models\MaintenanceRecord;
use App\Models\MaintenanceRequest;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Development-only maintenance demo data. Fully idempotent: demo assets are
 * keyed on their asset_code, requests on (asset, title), records on
 * (request, description), and parts on (record, item). Parts are seeded with
 * `firstOrCreate` and never trigger stock consumption, so re-running the
 * seeder cannot deduct inventory a second time.
 */
class MaintenanceSeeder extends Seeder
{
    public function run(): void
    {
        $staff = User::where('email', 'staff@nexora.test')->first() ?? User::query()->first();
        $technician = User::where('email', 'technician@nexora.test')->first() ?? User::query()->first();

        if (! $staff || ! $technician) {
            return;
        }

        $assetOne = $this->firstOrCreateAsset('MNT-AST-0001', 'CNC Milling Machine');
        $assetTwo = $this->firstOrCreateAsset('MNT-AST-0002', 'Backup Generator');

        $requests = [
            [
                'asset' => $assetOne,
                'title' => 'Spindle bearing is noisy',
                'description' => 'High-pitched whine from the spindle at high RPM.',
                'priority' => MaintenanceRequest::PRIORITY_HIGH,
                'status' => MaintenanceRequest::STATUS_IN_PROGRESS,
                'assignee' => $technician,
                'record' => [
                    'description' => 'Inspected spindle assembly and bearings.',
                    'result' => null,
                    'cost' => null,
                ],
                'parts' => ['MAINT-LUBE-01'],
            ],
            [
                'asset' => $assetTwo,
                'title' => 'Generator fails load test',
                'description' => 'Generator drops voltage when the load exceeds 40%.',
                'priority' => MaintenanceRequest::PRIORITY_URGENT,
                'status' => MaintenanceRequest::STATUS_REQUESTED,
                'assignee' => null,
                'record' => null,
                'parts' => [],
            ],
            [
                'asset' => $assetOne,
                'title' => 'Lubrication schedule overdue',
                'description' => 'Machine due for its quarterly lubrication service.',
                'priority' => MaintenanceRequest::PRIORITY_MEDIUM,
                'status' => MaintenanceRequest::STATUS_APPROVED,
                'assignee' => $technician,
                'record' => null,
                'parts' => [],
            ],
            [
                'asset' => $assetTwo,
                'title' => 'Cooling fan replaced',
                'description' => 'Radiator fan was seized and has been replaced.',
                'priority' => MaintenanceRequest::PRIORITY_LOW,
                'status' => MaintenanceRequest::STATUS_COMPLETED,
                'assignee' => $technician,
                'record' => [
                    'description' => 'Replaced seized radiator fan and reran the load test.',
                    'result' => 'Load test passed at full capacity.',
                    'cost' => 120.50,
                ],
                'parts' => ['CON-CABLE-LAN'],
            ],
        ];

        foreach ($requests as $request) {
            $model = $this->firstOrCreateRequest($request, $staff);

            $this->syncRequestState($model, $request);

            if ($request['record'] === null) {
                continue;
            }

            $record = $this->firstOrCreateRecord($model, $request['record'], $technician);

            foreach ($request['parts'] as $sku) {
                $item = Item::where('sku', $sku)->first();

                if (! $item) {
                    continue;
                }

                MaintenancePart::firstOrCreate(
                    ['maintenance_record_id' => $record->id, 'item_id' => $item->id],
                    ['quantity' => 1],
                );
            }
        }
    }

    private function firstOrCreateAsset(string $code, string $name): Asset
    {
        $categoryId = AssetCategory::query()->value('id');

        return Asset::updateOrCreate(
            ['asset_code' => $code],
            [
                'asset_category_id' => $categoryId,
                'name' => $name,
                'description' => 'Demo asset maintained via the seeded maintenance workflow.',
                'status' => 'ACTIVE',
                'condition' => 'GOOD',
            ]
        );
    }

    /**
     * @param  array<string, mixed>  $request
     */
    private function firstOrCreateRequest(array $request, User $staff): MaintenanceRequest
    {
        return MaintenanceRequest::updateOrCreate(
            ['asset_id' => $request['asset']->id, 'title' => $request['title']],
            [
                'requested_by' => $staff->id,
                'assigned_to' => $request['assignee']?->id,
                'description' => $request['description'],
                'priority' => $request['priority'],
                'status' => $request['status'],
                'requested_at' => now(),
            ]
        );
    }

    /**
     * Keep approved_at / completed_at consistent with the seeded status so a
     * re-seed can never leave a stale timestamp on a changed state.
     *
     * @param  array<string, mixed>  $request
     */
    private function syncRequestState(MaintenanceRequest $model, array $request): void
    {
        $status = $request['status'];
        $wasApproved = in_array($status, [
            MaintenanceRequest::STATUS_APPROVED,
            MaintenanceRequest::STATUS_IN_PROGRESS,
            MaintenanceRequest::STATUS_COMPLETED,
        ], true);
        $wasCompleted = $status === MaintenanceRequest::STATUS_COMPLETED;

        $model->update([
            'approved_at' => $wasApproved ? ($model->approved_at ?? now()) : null,
            'completed_at' => $wasCompleted ? ($model->completed_at ?? now()) : null,
        ]);
    }

    /**
     * @param  array{description: string, result: string|null, cost: float|null}  $data
     */
    private function firstOrCreateRecord(MaintenanceRequest $request, array $data, User $technician): MaintenanceRecord
    {
        return MaintenanceRecord::updateOrCreate(
            ['maintenance_request_id' => $request->id, 'description' => $data['description']],
            [
                'asset_id' => $request->asset_id,
                'technician_id' => $technician->id,
                'started_at' => now(),
                'completed_at' => $request->status === MaintenanceRequest::STATUS_COMPLETED ? now() : null,
                'result' => $data['result'],
                'cost' => $data['cost'],
            ]
        );
    }
}
