<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Index review for the Command Center activity feed (DR-019).
 *
 * The Command Center reads the newest rows of four append-only event tables
 * (`asset_histories`, `ticket_histories`, `maintenance_records` and
 * `stock_movements`) with an `ORDER BY created_at DESC LIMIT n` scan. Three of
 * those four had no index on `created_at` at all, so every dashboard load
 * sorted a growing history in the database.
 *
 * Only the columns this query actually orders by are indexed. Deliberately *not*
 * added:
 *
 *  - `tickets.assigned_to` / `maintenance_requests.assigned_to` — every queue
 *    query filters the status set first, and `status` is already indexed, so an
 *    `assigned_to` index would only duplicate a narrower scan;
 *  - any composite `(status, assigned_to)` index — that would be speculative
 *    until the queue tables are measured on production-sized data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asset_histories', function (Blueprint $table): void {
            $table->index('created_at', 'asset_histories_created_at_index');
        });

        Schema::table('ticket_histories', function (Blueprint $table): void {
            $table->index('created_at', 'ticket_histories_created_at_index');
        });

        Schema::table('maintenance_records', function (Blueprint $table): void {
            $table->index('created_at', 'maintenance_records_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('asset_histories', function (Blueprint $table): void {
            $table->dropIndex('asset_histories_created_at_index');
        });

        Schema::table('ticket_histories', function (Blueprint $table): void {
            $table->dropIndex('ticket_histories_created_at_index');
        });

        Schema::table('maintenance_records', function (Blueprint $table): void {
            $table->dropIndex('maintenance_records_created_at_index');
        });
    }
};
