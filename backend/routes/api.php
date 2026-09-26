<?php

use App\Http\Controllers\Api\AssetAssignmentController;
use App\Http\Controllers\Api\AssetCategoryController;
use App\Http\Controllers\Api\AssetController;
use App\Http\Controllers\Api\AssetQrController;
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CommandCenterController;
use App\Http\Controllers\Api\DepartmentController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\ItemCategoryController;
use App\Http\Controllers\Api\ItemController;
use App\Http\Controllers\Api\LocationController;
use App\Http\Controllers\Api\MaintenancePartController;
use App\Http\Controllers\Api\MaintenanceRecordController;
use App\Http\Controllers\Api\MaintenanceRequestController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\StockMovementController;
use App\Http\Controllers\Api\TicketCategoryController;
use App\Http\Controllers\Api\TicketController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\WarehouseController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Versioned API. Future modules attach here under the v1 prefix:
|
|   /api/v1/users
|   /api/v1/assets
|   /api/v1/inventory
|   /api/v1/tickets
|   /api/v1/maintenance
|
| Authentication uses Laravel Sanctum personal access tokens sent as
| Authorization: Bearer <token>. Public routes are rate limited; protected
| routes require the token.
|
*/

Route::prefix('v1')->group(static function (): void {
    Route::get('health', HealthController::class);

    Route::prefix('auth')->group(static function (): void {
        Route::post('register', [AuthController::class, 'register'])
            ->middleware('throttle:register')
            ->name('api.auth.register');

        Route::post('login', [AuthController::class, 'login'])
            ->middleware('throttle:login')
            ->name('api.auth.login');

        Route::middleware('auth:sanctum')->group(static function (): void {
            Route::post('logout', [AuthController::class, 'logout'])->name('api.auth.logout');
            Route::get('me', [AuthController::class, 'me'])->name('api.auth.me');
        });
    });

    Route::middleware('auth:sanctum')->group(static function (): void {
        Route::middleware('permission:view_departments')->group(static function (): void {
            Route::get('departments', [DepartmentController::class, 'index'])->name('api.departments.index');
            Route::get('departments/{department}', [DepartmentController::class, 'show'])->name('api.departments.show');
        });

        Route::middleware('permission:manage_departments')->group(static function (): void {
            Route::post('departments', [DepartmentController::class, 'store'])->name('api.departments.store');
            Route::put('departments/{department}', [DepartmentController::class, 'update'])->name('api.departments.update');
            Route::delete('departments/{department}', [DepartmentController::class, 'destroy'])->name('api.departments.destroy');
        });

        Route::middleware('permission:view_locations')->group(static function (): void {
            Route::get('locations', [LocationController::class, 'index'])->name('api.locations.index');
            Route::get('locations/{location}', [LocationController::class, 'show'])->name('api.locations.show');
        });

        Route::middleware('permission:manage_locations')->group(static function (): void {
            Route::post('locations', [LocationController::class, 'store'])->name('api.locations.store');
            Route::put('locations/{location}', [LocationController::class, 'update'])->name('api.locations.update');
            Route::delete('locations/{location}', [LocationController::class, 'destroy'])->name('api.locations.destroy');
        });

        Route::middleware('permission:view_users')->group(static function (): void {
            Route::get('users', [UserController::class, 'index'])->name('api.users.index');
            Route::get('users/{user}', [UserController::class, 'show'])->name('api.users.show');
        });

        Route::middleware('permission:manage_users')->group(static function (): void {
            Route::post('users', [UserController::class, 'store'])->name('api.users.store');
            Route::put('users/{user}', [UserController::class, 'update'])->name('api.users.update');
            Route::delete('users/{user}', [UserController::class, 'destroy'])->name('api.users.destroy');
        });

        // Asset Categories
        Route::middleware('permission:view_asset_categories')->group(static function (): void {
            Route::get('asset-categories', [AssetCategoryController::class, 'index'])->name('api.asset_categories.index');
            Route::get('asset-categories/{assetCategory}', [AssetCategoryController::class, 'show'])->name('api.asset_categories.show');
        });

        Route::middleware('permission:manage_asset_categories')->group(static function (): void {
            Route::post('asset-categories', [AssetCategoryController::class, 'store'])->name('api.asset_categories.store');
            Route::put('asset-categories/{assetCategory}', [AssetCategoryController::class, 'update'])->name('api.asset_categories.update');
            Route::delete('asset-categories/{assetCategory}', [AssetCategoryController::class, 'destroy'])->name('api.asset_categories.destroy');
        });

        // Assets
        Route::middleware('permission:view_assets')->group(static function (): void {
            Route::get('assets', [AssetController::class, 'index'])->name('api.assets.index');
            Route::get('assets/{asset}', [AssetController::class, 'show'])->name('api.assets.show');
        });

        Route::middleware('permission:manage_assets')->group(static function (): void {
            Route::post('assets', [AssetController::class, 'store'])->name('api.assets.store');
            Route::put('assets/{asset}', [AssetController::class, 'update'])->name('api.assets.update');
            Route::delete('assets/{asset}', [AssetController::class, 'destroy'])->name('api.assets.destroy');
        });

        // Asset Assignments
        Route::middleware('permission:view_asset_assignments')->group(static function (): void {
            Route::get('asset-assignments', [AssetAssignmentController::class, 'index'])->name('api.asset_assignments.index');
            Route::get('asset-assignments/{assetAssignment}', [AssetAssignmentController::class, 'show'])->name('api.asset_assignments.show');
        });

        Route::middleware('permission:manage_asset_assignments')->group(static function (): void {
            Route::post('asset-assignments', [AssetAssignmentController::class, 'store'])->name('api.asset_assignments.store');
            Route::post('asset-assignments/{assetAssignment}/return', [AssetAssignmentController::class, 'return'])->name('api.asset_assignments.return');
        });

        // QR Asset Identification
        Route::middleware('permission:view_assets')->group(static function (): void {
            Route::get('assets/{asset}/qr', [AssetQrController::class, 'metadata'])->name('api.assets.qr.metadata');
            Route::get('assets/qr/{identifier}', [AssetQrController::class, 'lookup'])->name('api.assets.qr.lookup');
        });

        // Item Categories
        Route::middleware('permission:view_inventory')->group(static function (): void {
            Route::get('item-categories', [ItemCategoryController::class, 'index'])->name('api.item_categories.index');
            Route::get('item-categories/{itemCategory}', [ItemCategoryController::class, 'show'])->name('api.item_categories.show');
        });

        Route::middleware('permission:manage_inventory')->group(static function (): void {
            Route::post('item-categories', [ItemCategoryController::class, 'store'])->name('api.item_categories.store');
            Route::put('item-categories/{itemCategory}', [ItemCategoryController::class, 'update'])->name('api.item_categories.update');
            Route::delete('item-categories/{itemCategory}', [ItemCategoryController::class, 'destroy'])->name('api.item_categories.destroy');
        });

        // Items
        Route::middleware('permission:view_inventory')->group(static function (): void {
            Route::get('items', [ItemController::class, 'index'])->name('api.items.index');
            Route::get('items/{item}', [ItemController::class, 'show'])->name('api.items.show');
        });

        Route::middleware('permission:manage_inventory')->group(static function (): void {
            Route::post('items', [ItemController::class, 'store'])->name('api.items.store');
            Route::put('items/{item}', [ItemController::class, 'update'])->name('api.items.update');
            Route::delete('items/{item}', [ItemController::class, 'destroy'])->name('api.items.destroy');
        });

        // Warehouses
        Route::middleware('permission:view_inventory')->group(static function (): void {
            Route::get('warehouses', [WarehouseController::class, 'index'])->name('api.warehouses.index');
            Route::get('warehouses/{warehouse}', [WarehouseController::class, 'show'])->name('api.warehouses.show');
        });

        Route::middleware('permission:manage_inventory')->group(static function (): void {
            Route::post('warehouses', [WarehouseController::class, 'store'])->name('api.warehouses.store');
            Route::put('warehouses/{warehouse}', [WarehouseController::class, 'update'])->name('api.warehouses.update');
            Route::delete('warehouses/{warehouse}', [WarehouseController::class, 'destroy'])->name('api.warehouses.destroy');
        });

        // Stock Movements (append-only journal: no update/delete endpoints)
        Route::middleware('permission:view_inventory')->group(static function (): void {
            Route::get('stock-movements', [StockMovementController::class, 'index'])->name('api.stock_movements.index');
            Route::get('stock-movements/{stockMovement}', [StockMovementController::class, 'show'])->name('api.stock_movements.show');
        });

        Route::middleware('permission:manage_stock')->group(static function (): void {
            Route::post('stock-movements', [StockMovementController::class, 'store'])->name('api.stock_movements.store');
        });

        // Ticket Categories
        Route::middleware('permission:view_tickets')->group(static function (): void {
            Route::get('ticket-categories', [TicketCategoryController::class, 'index'])->name('api.ticket_categories.index');
            Route::get('ticket-categories/{ticketCategory}', [TicketCategoryController::class, 'show'])->name('api.ticket_categories.show');
        });

        Route::middleware('permission:manage_tickets')->group(static function (): void {
            Route::post('ticket-categories', [TicketCategoryController::class, 'store'])->name('api.ticket_categories.store');
            Route::put('ticket-categories/{ticketCategory}', [TicketCategoryController::class, 'update'])->name('api.ticket_categories.update');
            Route::delete('ticket-categories/{ticketCategory}', [TicketCategoryController::class, 'destroy'])->name('api.ticket_categories.destroy');
        });

        // Tickets (workflow, comments, and history live on the ticket resource;
        // updates are explicit PUT transitions, never ad-hoc status endpoints)
        Route::middleware('permission:view_tickets')->group(static function (): void {
            Route::get('tickets', [TicketController::class, 'index'])->name('api.tickets.index');
            Route::post('tickets', [TicketController::class, 'store'])->name('api.tickets.store');
            Route::get('tickets/{ticket}', [TicketController::class, 'show'])->name('api.tickets.show');
            Route::get('tickets/{ticket}/comments', [TicketController::class, 'comments'])->name('api.tickets.comments.index');
            Route::post('tickets/{ticket}/comments', [TicketController::class, 'storeComment'])->name('api.tickets.comments.store');
            Route::get('tickets/{ticket}/history', [TicketController::class, 'history'])->name('api.tickets.history.index');
        });

        Route::middleware('permission:manage_tickets')->group(static function (): void {
            Route::put('tickets/{ticket}', [TicketController::class, 'update'])->name('api.tickets.update');
        });

        // Maintenance Requests (reporting is open to view_maintenance, like
        // creating tickets; workflow, assignment, and record/part writes are the
        // manage_maintenance capability)
        Route::middleware('permission:view_maintenance')->group(static function (): void {
            Route::get('maintenance-requests', [MaintenanceRequestController::class, 'index'])->name('api.maintenance_requests.index');
            Route::post('maintenance-requests', [MaintenanceRequestController::class, 'store'])->name('api.maintenance_requests.store');
            Route::get('maintenance-requests/{maintenanceRequest}', [MaintenanceRequestController::class, 'show'])->name('api.maintenance_requests.show');
            Route::get('maintenance-records', [MaintenanceRecordController::class, 'index'])->name('api.maintenance_records.index');
            Route::get('maintenance-records/{maintenanceRecord}', [MaintenanceRecordController::class, 'show'])->name('api.maintenance_records.show');
            Route::get('maintenance-records/{maintenanceRecord}/parts', [MaintenancePartController::class, 'index'])->name('api.maintenance_records.parts.index');
        });

        Route::middleware('permission:manage_maintenance')->group(static function (): void {
            Route::put('maintenance-requests/{maintenanceRequest}', [MaintenanceRequestController::class, 'update'])->name('api.maintenance_requests.update');
            Route::post('maintenance-records', [MaintenanceRecordController::class, 'store'])->name('api.maintenance_records.store');
            Route::put('maintenance-records/{maintenanceRecord}', [MaintenanceRecordController::class, 'update'])->name('api.maintenance_records.update');
            Route::post('maintenance-records/{maintenanceRecord}/parts', [MaintenancePartController::class, 'store'])->name('api.maintenance_records.parts.store');
        });

        // Notifications (per-user inbox; no permission gate — ownership is
        // enforced on every read path. There is no create endpoint: rows are
        // written server-side by the domain services only.)
        Route::get('notifications', [NotificationController::class, 'index'])->name('api.notifications.index');
        Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount'])->name('api.notifications.unread-count');
        Route::post('notifications/read-all', [NotificationController::class, 'readAll'])->name('api.notifications.read-all');
        Route::get('notifications/{notification}', [NotificationController::class, 'show'])->name('api.notifications.show');
        Route::post('notifications/{notification}/read', [NotificationController::class, 'read'])->name('api.notifications.read');

        // Command Center (read-only current operational snapshot: state, work
        // queues, recent activity. No period parameter and no write verb — see
        // DR-019. Scoped to what the caller may read, unlike the global reports.)
        Route::middleware('permission:view_dashboard')->group(static function (): void {
            Route::get('command-center', CommandCenterController::class)->name('api.command_center.index');
        });

        // Reports (read-only aggregates across assets, inventory, helpdesk and
        // maintenance; no write path exists — see DR-018)
        Route::middleware('permission:view_reports')->group(static function (): void {
            Route::get('reports/overview', [ReportController::class, 'overview'])->name('api.reports.overview');
            Route::get('reports/assets', [ReportController::class, 'assets'])->name('api.reports.assets');
            Route::get('reports/inventory', [ReportController::class, 'inventory'])->name('api.reports.inventory');
            Route::get('reports/tickets', [ReportController::class, 'tickets'])->name('api.reports.tickets');
            Route::get('reports/maintenance', [ReportController::class, 'maintenance'])->name('api.reports.maintenance');
        });

        // Audit Logs (read-only governance trail; written only by the domain
        // services through AuditLogService — no write verb exists, see DR-020)
        Route::middleware('permission:view_audit_logs')->group(static function (): void {
            Route::get('audit-logs', [AuditLogController::class, 'index'])->name('api.audit_logs.index');
            Route::get('audit-logs/{auditLog}', [AuditLogController::class, 'show'])->name('api.audit_logs.show');
        });
    });
});
