<?php

namespace Tests\Feature\Http\Audit;

use App\Models\AuditLog;
use App\Models\Item;
use App\Models\StockMovement;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\Audit\AuditResourceType;
use Illuminate\Support\Facades\Hash;

// Governance events are written by the domain services — one audit row per
// successful mutation, none for failed or no-op mutations, and never any
// secret. Immutability is structural: no HTTP write path exists at all.

class AuditLogIntegrationTest extends AuditLogTestCase
{
    public function test_records_one_audit_event_for_a_successful_user_creation(): void
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)->postJson('/api/v1/users', [
            'name' => 'New Manager',
            'email' => 'new.manager@nexora.test',
            'password' => 'super-secret-123',
            'password_confirmation' => 'super-secret-123',
            'role_id' => $this->roleId('manager'),
        ])->assertStatus(201);

        $log = AuditLog::query()->where('entity_type', AuditResourceType::USER)->sole();

        $this->assertSame('created', $log->action);
        $this->assertSame($admin->id, $log->user_id);
        $this->assertSame(
            (int) User::where('email', 'new.manager@nexora.test')->value('id'),
            (int) $log->entity_id,
        );
    }

    public function test_never_records_the_password_value_in_the_audit_trail(): void
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)->postJson('/api/v1/users', [
            'name' => 'Secret Keeper',
            'email' => 'secret.keeper@nexora.test',
            'password' => 'top-secret-value',
            'password_confirmation' => 'top-secret-value',
            'role_id' => $this->roleId('manager'),
        ])->assertStatus(201);

        $content = AuditLog::query()->where('entity_type', 'user')->get()
            ->map(fn (AuditLog $log) => json_encode([$log->description, $log->old_values, $log->new_values]))
            ->implode('|');

        $this->assertStringNotContainsString('top-secret-value', $content);
    }

    public function test_records_an_updated_audit_event_with_a_role_change_delta(): void
    {
        $admin = $this->userWithRole('admin');
        $target = $this->userWithRole('staff');

        $this->actingAs($admin)
            ->putJson('/api/v1/users/'.$target->id, ['role_id' => $this->roleId('manager')])
            ->assertStatus(200);

        $log = AuditLog::query()
            ->where('entity_type', AuditResourceType::USER)
            ->where('action', 'updated')
            ->sole();

        $this->assertSame('staff', $log->old_values['role_id']);
        $this->assertSame('manager', $log->new_values['role_id']);
    }

    public function test_does_not_record_an_audit_event_for_a_failed_user_creation(): void
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)->postJson('/api/v1/users', [
            'name' => 'Broken',
            'email' => 'not-an-email',
            'password' => 'short',
            'role_id' => $this->roleId('manager'),
        ])->assertStatus(422);

        $this->assertSame(0, AuditLog::query()->where('entity_type', AuditResourceType::USER)->count());
    }

    public function test_does_not_record_an_audit_event_for_a_no_op_user_update(): void
    {
        $admin = $this->userWithRole('admin');
        $target = $this->userWithRole('staff');

        $this->actingAs($admin)
            ->putJson('/api/v1/users/'.$target->id, [
                'role_id' => $this->roleId('staff'),
            ])
            ->assertStatus(200);

        $this->assertSame(0, AuditLog::query()->where('entity_type', AuditResourceType::USER)->count());
    }

    public function test_records_a_status_changed_audit_event_when_a_ticket_transitions(): void
    {
        $admin = $this->userWithRole('admin');
        $category = TicketCategory::create(['name' => 'Hardware', 'code' => 'HW'.uniqid()]);

        $ticket = Ticket::create([
            'ticket_number' => 'TCK-'.strtoupper(substr(uniqid(), -8)),
            'title' => 'Audit me',
            'description' => 'Testing audit on status transition',
            'category_id' => $category->id,
            'requester_id' => $admin->id,
            'priority' => 'MEDIUM',
            'status' => 'OPEN',
            'closed_at' => null,
        ]);

        $this->actingAs($admin)
            ->putJson('/api/v1/tickets/'.$ticket->id, ['status' => 'IN_PROGRESS'])
            ->assertStatus(200);

        $log = AuditLog::query()
            ->where('entity_type', AuditResourceType::TICKET)
            ->where('action', 'status_changed')
            ->sole();

        $this->assertSame("Ticket {$ticket->ticket_number} status changed from OPEN to IN_PROGRESS", $log->description);
        $this->assertSame(['status' => 'OPEN'], $log->old_values);
        $this->assertSame(['status' => 'IN_PROGRESS'], $log->new_values);
    }

    public function test_does_not_record_an_audit_event_for_an_invalid_ticket_transition(): void
    {
        $admin = $this->userWithRole('admin');
        $category = TicketCategory::create(['name' => 'Software', 'code' => 'SW'.uniqid()]);

        $ticket = Ticket::create([
            'ticket_number' => 'TCK-'.strtoupper(substr(uniqid(), -8)),
            'title' => 'Invalid transition',
            'description' => 'Should not be audited as a success',
            'category_id' => $category->id,
            'requester_id' => $admin->id,
            'priority' => 'MEDIUM',
            'status' => 'OPEN',
            'closed_at' => null,
        ]);

        $this->actingAs($admin)
            ->putJson('/api/v1/tickets/'.$ticket->id, ['status' => 'CLOSED'])
            ->assertStatus(422);

        $this->assertSame(0, AuditLog::query()->where('entity_type', AuditResourceType::TICKET)->count());
    }

    public function test_does_not_record_an_audit_event_for_a_same_status_no_op_ticket_update(): void
    {
        $admin = $this->userWithRole('admin');
        $category = TicketCategory::create(['name' => 'General', 'code' => 'GEN'.uniqid()]);

        $ticket = Ticket::create([
            'ticket_number' => 'TCK-'.strtoupper(substr(uniqid(), -8)),
            'title' => 'No-op update',
            'description' => 'Same status resend',
            'category_id' => $category->id,
            'requester_id' => $admin->id,
            'priority' => 'MEDIUM',
            'status' => 'OPEN',
            'closed_at' => null,
        ]);

        $this->actingAs($admin)
            ->putJson('/api/v1/tickets/'.$ticket->id, ['status' => 'OPEN'])
            ->assertStatus(200);

        $this->assertSame(0, AuditLog::query()->where('entity_type', AuditResourceType::TICKET)->count());
    }

    public function test_records_a_stock_movement_audit_event_inside_the_inventory_transaction(): void
    {
        $warehouseStaff = $this->userWithRole('warehouse_staff');
        $item = Item::factory()->create();
        $warehouse = Warehouse::factory()->create();

        $this->actingAs($warehouseStaff)->postJson('/api/v1/stock-movements', [
            'item_id' => $item->id,
            'warehouse_id' => $warehouse->id,
            'type' => 'STOCK_IN',
            'quantity' => 5,
        ])->assertStatus(201);

        $log = AuditLog::query()
            ->where('entity_type', AuditResourceType::STOCK_MOVEMENT)
            ->where('action', 'created')
            ->sole();

        $this->assertSame(5, $log->new_values['quantity']);
        $this->assertSame($warehouseStaff->id, $log->user_id);
        $this->assertSame(1, StockMovement::query()->count());
    }

    public function test_does_not_record_an_audit_event_when_a_stock_movement_fails(): void
    {
        $warehouseStaff = $this->userWithRole('warehouse_staff');
        $item = Item::factory()->create();
        $warehouse = Warehouse::factory()->create();

        // STOCK_OUT with no prior STOCK_IN drives the balance below zero → 422.
        $this->actingAs($warehouseStaff)->postJson('/api/v1/stock-movements', [
            'item_id' => $item->id,
            'warehouse_id' => $warehouse->id,
            'type' => 'STOCK_OUT',
            'quantity' => 10,
        ])->assertStatus(422);

        $this->assertSame(0, AuditLog::query()->where('entity_type', AuditResourceType::STOCK_MOVEMENT)->count());
    }

    public function test_records_login_and_logout_governance_events_without_credentials(): void
    {
        $user = User::factory()->create([
            'email' => 'auth.audit@nexora.test',
            'password' => Hash::make('correct-password'),
            'is_active' => true,
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'auth.audit@nexora.test',
            'password' => 'correct-password',
        ])->assertStatus(200);

        $loginLog = AuditLog::query()->where('action', 'logged_in')->sole();

        $this->assertSame($user->id, $loginLog->user_id);
        $this->assertSame(AuditResourceType::USER, $loginLog->entity_type);

        // A failed login never produces a logged_in row.
        $this->postJson('/api/v1/auth/login', [
            'email' => 'auth.audit@nexora.test',
            'password' => 'wrong-password',
        ])->assertStatus(401);

        $this->assertSame(1, AuditLog::query()->where('action', 'logged_in')->count());

        $this->assertStringNotContainsString(
            'correct-password',
            (string) AuditLog::query()->where('action', 'logged_in')->value('description'),
        );
    }

    public function test_does_not_record_audit_events_for_reads(): void
    {
        $actor = $this->userWithRole('admin');

        $this->actingAs($actor)->getJson('/api/v1/users')->assertStatus(200);
        $this->actingAs($actor)->getJson('/api/v1/assets')->assertStatus(200);
        $this->actingAs($actor)->getJson('/api/v1/tickets')->assertStatus(200);
        $this->actingAs($actor)->getJson('/api/v1/reports/overview')->assertStatus(200);
        $this->actingAs($actor)->getJson(self::ENDPOINT)->assertStatus(200);

        $this->assertSame(0, AuditLog::query()->count());
    }

    public function test_captures_the_request_ip_address_safely(): void
    {
        $admin = $this->userWithRole('admin');
        $target = $this->userWithRole('staff');

        $this->actingAs($admin)
            ->putJson('/api/v1/users/'.$target->id, ['name' => 'Renamed Staff'])
            ->assertStatus(200);

        $log = AuditLog::query()
            ->where('entity_type', AuditResourceType::USER)
            ->where('action', 'updated')
            ->sole();

        $this->assertNotNull($log->ip_address);
        $this->assertLessThanOrEqual(45, strlen((string) $log->ip_address));
    }

    public function test_survives_the_actor_being_deleted(): void
    {
        $admin = $this->userWithRole('admin');
        $actor = $this->userWithRole('admin');

        // The actor holds no blocking references, so deletion is allowed and
        // the FK nulls the audit row's user_id (nullOnDelete).
        $target = $this->userWithRole('staff');
        $this->actingAs($actor)
            ->putJson('/api/v1/users/'.$target->id, ['name' => 'Renamed'])
            ->assertStatus(200);

        $log = AuditLog::query()
            ->where('entity_type', AuditResourceType::USER)
            ->where('action', 'updated')
            ->sole();
        $this->assertSame($actor->id, $log->user_id);

        $this->actingAs($admin)->deleteJson('/api/v1/users/'.$actor->id)->assertStatus(200);

        $log->refresh();
        $this->assertNull($log->user_id);

        // The API renders the deleted actor gracefully instead of failing.
        $this->actingAs($admin)
            ->getJson(self::ENDPOINT.'/'.$log->id)
            ->assertStatus(200)
            ->assertJsonPath('data.actor', null);
    }
}
