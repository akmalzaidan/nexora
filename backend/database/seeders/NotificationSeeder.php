<?php

namespace Database\Seeders;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Database\Seeder;

class NotificationSeeder extends Seeder
{
    /**
     * Seed a small inbox of notifications for the development accounts.
     *
     * Purely developmental sample data. Rows are only inserted when the
     * (user_id, type, title) triple does not exist yet, so re-running this
     * seeder never duplicates notifications and the inbox stays stable.
     */
    public function run(): void
    {
        $byEmail = static fn (string $email): int => (int) User::where('email', $email)->value('id');

        $definitions = [
            [
                'email' => 'admin@nexora.test',
                'type' => Notification::TYPE_TICKET_ASSIGNED,
                'title' => 'Ticket assigned to you',
                'message' => 'Ticket TCK-DEMO-0001 has been assigned to you.',
                'data' => ['ticket_id' => 1, 'ticket_number' => 'TCK-DEMO-0001'],
                'read_at' => null,
            ],
            [
                'email' => 'admin@nexora.test',
                'type' => Notification::TYPE_TICKET_STATUS_CHANGED,
                'title' => 'Ticket status updated',
                'message' => 'Ticket TCK-DEMO-0001 is now RESOLVED.',
                'data' => ['ticket_id' => 1, 'ticket_number' => 'TCK-DEMO-0001', 'status' => 'RESOLVED'],
                'read_at' => now()->subDay(),
            ],
            [
                'email' => 'technician@nexora.test',
                'type' => Notification::TYPE_MAINTENANCE_ASSIGNED,
                'title' => 'Maintenance request assigned to you',
                'message' => 'Maintenance request #1 has been assigned to you.',
                'data' => ['maintenance_request_id' => 1],
                'read_at' => null,
            ],
            [
                'email' => 'staff@nexora.test',
                'type' => Notification::TYPE_MAINTENANCE_APPROVED,
                'title' => 'Maintenance request approved',
                'message' => 'Maintenance request #1 has been approved.',
                'data' => ['maintenance_request_id' => 1],
                'read_at' => now()->subHours(2),
            ],
            [
                'email' => 'staff@nexora.test',
                'type' => Notification::TYPE_ASSET_ASSIGNED,
                'title' => 'Asset assigned to you',
                'message' => 'Asset AST-DEMO-0001 has been assigned to you.',
                'data' => ['asset_id' => 1, 'assignment_id' => 1],
                'read_at' => null,
            ],
        ];

        foreach ($definitions as $definition) {
            $userId = $byEmail($definition['email']);

            if ($userId === 0) {
                continue;
            }

            Notification::firstOrCreate(
                [
                    'user_id' => $userId,
                    'type' => $definition['type'],
                    'title' => $definition['title'],
                ],
                [
                    'message' => $definition['message'],
                    'data' => $definition['data'],
                    'read_at' => $definition['read_at'],
                ],
            );
        }
    }
}
