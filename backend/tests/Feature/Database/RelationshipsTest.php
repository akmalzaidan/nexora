<?php

namespace Tests\Feature\Database;

use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\Item;
use App\Models\StockMovement;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RelationshipsTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_belongs_to_role_and_department(): void
    {
        $this->seed();

        $user = User::where('email', 'warehouse@nexora.test')->firstOrFail();

        $this->assertSame('warehouse_staff', $user->role->slug);
        $this->assertSame('WH', $user->department->code);
    }

    public function test_asset_honours_current_user_and_assignments(): void
    {
        $user = User::factory()->create();
        $asset = Asset::factory()->create(['current_user_id' => $user->id]);

        $assignment = AssetAssignment::create([
            'asset_id' => $asset->id,
            'user_id' => $user->id,
            'requested_by' => $user->id,
            'status' => 'PENDING',
        ]);

        $this->assertTrue($asset->currentUser->is($user));
        $this->assertTrue($asset->category !== null);
        $this->assertTrue($asset->assignments->contains($assignment));
    }

    public function test_ticket_requester_comments_and_histories(): void
    {
        $requester = User::factory()->create();
        $ticket = Ticket::factory()->create(['requester_id' => $requester->id]);

        $comment = TicketComment::create([
            'ticket_id' => $ticket->id,
            'user_id' => $requester->id,
            'comment' => 'Please take a look',
        ]);

        $this->assertTrue($ticket->requester->is($requester));
        $this->assertTrue($ticket->comments->contains($comment));

        $history = $ticket->histories()->create([
            'user_id' => $requester->id,
            'action' => 'STATUS_CHANGED',
            'old_status' => 'OPEN',
            'new_status' => 'IN_PROGRESS',
        ]);

        $this->assertSame('IN_PROGRESS', $history->new_status);
    }

    public function test_stock_movement_chain_item_warehouse_performer(): void
    {
        $user = User::factory()->create();
        $item = Item::factory()->create();
        $warehouse = Warehouse::create([
            'name' => 'Temp Warehouse',
            'code' => 'TMP-WH',
        ]);

        $movement = StockMovement::create([
            'item_id' => $item->id,
            'warehouse_id' => $warehouse->id,
            'type' => 'IN',
            'quantity' => 10,
            'performed_by' => $user->id,
        ]);

        $this->assertTrue($movement->item->is($item));
        $this->assertTrue($movement->warehouse->is($warehouse));
        $this->assertTrue($movement->performer->is($user));
        $this->assertTrue($item->stockMovements->contains($movement));
    }
}
