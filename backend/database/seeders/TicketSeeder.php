<?php

namespace Database\Seeders;

use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\TicketComment;
use App\Models\TicketHistory;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Development-only demo tickets. Idempotent: tickets are keyed on their unique
 * ticket_number, and every comment/history row carries a deterministic key so
 * re-running the seeder never duplicates the timeline.
 */
class TicketSeeder extends Seeder
{
    public function run(): void
    {
        $staff = User::where('email', 'staff@nexora.test')->first() ?? User::query()->first();
        $manager = User::where('email', 'manager@nexora.test')->first() ?? User::query()->first();
        $technician = User::where('email', 'technician@nexora.test')->first() ?? User::query()->first();

        if (! $staff || ! $manager || ! $technician) {
            return;
        }

        $tickets = [
            [
                'ticket_number' => 'TCK-DEMO-0001',
                'title' => 'Cannot access shared drive',
                'description' => 'The shared drive mapping drops after every restart.',
                'category_code' => 'NETWORK',
                'requester' => $staff,
                'assignee' => $technician,
                'priority' => Ticket::PRIORITY_HIGH,
                'status' => Ticket::STATUS_IN_PROGRESS,
            ],
            [
                'ticket_number' => 'TCK-DEMO-0002',
                'title' => 'Printer not responding',
                'description' => 'The lobby printer shows offline even after a reboot.',
                'category_code' => 'HARDWARE',
                'requester' => $staff,
                'assignee' => null,
                'priority' => Ticket::PRIORITY_MEDIUM,
                'status' => Ticket::STATUS_OPEN,
            ],
            [
                'ticket_number' => 'TCK-DEMO-0003',
                'title' => 'Password reset for ERP access',
                'description' => 'Requesting a password reset for the ERP portal.',
                'category_code' => 'ACCOUNT',
                'requester' => $staff,
                'assignee' => $technician,
                'priority' => Ticket::PRIORITY_LOW,
                'status' => Ticket::STATUS_RESOLVED,
            ],
            [
                'ticket_number' => 'TCK-DEMO-0004',
                'title' => 'Slow video call quality',
                'description' => 'Video calls in the meeting room are choppy.',
                'category_code' => 'NETWORK',
                'requester' => $manager,
                'assignee' => $technician,
                'priority' => Ticket::PRIORITY_URGENT,
                'status' => Ticket::STATUS_CLOSED,
            ],
        ];

        foreach ($tickets as $ticket) {
            $category = TicketCategory::where('code', $ticket['category_code'])->first();

            $created = $this->firstOrCreateTicket($ticket, $category?->id);
            $closedAt = $ticket['status'] === Ticket::STATUS_CLOSED ? $created->created_at : null;

            $created->update(['closed_at' => $closedAt]);

            $chain = $this->historyChain(
                $ticket['status'],
                $ticket['assignee'],
                $ticket['requester'],
            );

            $offset = 0;
            foreach ($chain as $entry) {
                $entry['ticket_id'] = $created->id;
                $entry['user_id'] ??= $ticket['assignee']?->id ?? $ticket['requester']->id;
                $entry['created_at'] = $created->created_at->copy()->addSeconds($offset++);
                $this->firstOrCreateHistory($entry);
            }
        }

        $this->seedComments($tickets, $staff, $technician, $manager);
    }

    /**
     * @param  array<string, mixed>  $ticket
     */
    private function firstOrCreateTicket(array $ticket, ?int $categoryId): Ticket
    {
        return Ticket::updateOrCreate(
            ['ticket_number' => $ticket['ticket_number']],
            [
                'title' => $ticket['title'],
                'description' => $ticket['description'],
                'category_id' => $categoryId,
                'requester_id' => $ticket['requester']->id,
                'assigned_to' => $ticket['assignee']?->id,
                'priority' => $ticket['priority'],
                'status' => $ticket['status'],
                'closed_at' => null,
            ]
        );
    }

    /**
     * Rebuild the canonical history for a seeded status so the timeline always
     * matches the current state. Deterministic keys keep reseeding idempotent.
     *
     * @return list<array<string, mixed>>
     */
    private function historyChain(string $status, ?User $assignee, User $requester): array
    {
        $chain = [
            [
                'action' => TicketHistory::ACTION_CREATED,
                'old_status' => null,
                'new_status' => null,
                'notes' => 'Ticket raised',
                'user_id' => $requester->id,
            ],
        ];

        if ($assignee) {
            $chain[] = [
                'action' => TicketHistory::ACTION_ASSIGNMENT_CHANGED,
                'old_status' => null,
                'new_status' => null,
                'notes' => "Assigned to {$assignee->name}",
            ];
        }

        if (in_array($status, [Ticket::STATUS_IN_PROGRESS, Ticket::STATUS_RESOLVED, Ticket::STATUS_CLOSED], true)) {
            $chain[] = [
                'action' => TicketHistory::ACTION_STATUS_CHANGED,
                'old_status' => Ticket::STATUS_OPEN,
                'new_status' => Ticket::STATUS_IN_PROGRESS,
                'notes' => null,
            ];
        }

        if (in_array($status, [Ticket::STATUS_RESOLVED, Ticket::STATUS_CLOSED], true)) {
            $chain[] = [
                'action' => TicketHistory::ACTION_STATUS_CHANGED,
                'old_status' => Ticket::STATUS_IN_PROGRESS,
                'new_status' => Ticket::STATUS_RESOLVED,
                'notes' => null,
            ];
        }

        if ($status === Ticket::STATUS_CLOSED) {
            $chain[] = [
                'action' => TicketHistory::ACTION_STATUS_CHANGED,
                'old_status' => Ticket::STATUS_RESOLVED,
                'new_status' => Ticket::STATUS_CLOSED,
                'notes' => null,
            ];
        }

        return $chain;
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function firstOrCreateHistory(array $entry): void
    {
        TicketHistory::firstOrCreate(
            [
                'ticket_id' => $entry['ticket_id'],
                'action' => $entry['action'],
                'old_status' => $entry['old_status'],
                'new_status' => $entry['new_status'],
                'notes' => $entry['notes'],
            ],
            [
                'user_id' => $entry['user_id'],
                'created_at' => $entry['created_at'],
            ]
        );
    }

    /**
     * @param  list<array<string, mixed>>  $tickets
     */
    private function seedComments(array $tickets, User $staff, User $technician, User $manager): void
    {
        $samples = [
            'TCK-DEMO-0001' => [
                ['user' => $technician, 'comment' => 'Restarting the DNS service and re-testing the mapping.', 'is_internal' => true],
                ['user' => $staff, 'comment' => 'Working again after the DNS restart.', 'is_internal' => false],
            ],
            'TCK-DEMO-0002' => [
                ['user' => $staff, 'comment' => 'Printer still offline this morning.', 'is_internal' => false],
            ],
            'TCK-DEMO-0003' => [
                ['user' => $technician, 'comment' => 'Password rotated; user confirmed access.', 'is_internal' => false],
            ],
            'TCK-DEMO-0004' => [
                ['user' => $manager, 'comment' => 'Please treat this as urgent.', 'is_internal' => false],
                ['user' => $technician, 'comment' => 'Replaced the network drop in the meeting room.', 'is_internal' => true],
            ],
        ];

        foreach ($samples as $number => $comments) {
            $ticket = Ticket::where('ticket_number', $number)->first();

            if (! $ticket) {
                continue;
            }

            foreach ($comments as $comment) {
                TicketComment::firstOrCreate(
                    [
                        'ticket_id' => $ticket->id,
                        'user_id' => $comment['user']->id,
                        'comment' => $comment['comment'],
                    ],
                    ['is_internal' => $comment['is_internal'], 'created_at' => $ticket->created_at->addSecond()]
                );
            }
        }
    }
}
