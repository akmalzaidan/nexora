<?php

namespace App\Support\Audit;

/**
 * Controlled audit resource-type vocabulary (Phase 20A).
 *
 * Values map to `audit_logs.entity_type` and use stable snake_case domain
 * identifiers — never PHP class names — so the API contract survives
 * refactors. Only resource types that actually emit audit events today are
 * listed; a new domain module must add its identifier here when it starts
 * writing audit rows.
 */
final class AuditResourceType
{
    public const USER = 'user';

    public const ASSET = 'asset';

    public const ASSET_ASSIGNMENT = 'asset_assignment';

    public const ITEM = 'item';

    public const ITEM_CATEGORY = 'item_category';

    public const WAREHOUSE = 'warehouse';

    public const STOCK_MOVEMENT = 'stock_movement';

    public const TICKET = 'ticket';

    public const MAINTENANCE_REQUEST = 'maintenance_request';

    public const MAINTENANCE_RECORD = 'maintenance_record';

    /**
     * All resource types that may appear in `audit_logs.entity_type`, used to
     * whitelist the `resource_type` filter on the read API. Only types whose
     * modules actually emit audit events are listed.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::USER,
            self::ASSET,
            self::ASSET_ASSIGNMENT,
            self::ITEM,
            self::ITEM_CATEGORY,
            self::WAREHOUSE,
            self::STOCK_MOVEMENT,
            self::TICKET,
            self::MAINTENANCE_REQUEST,
            self::MAINTENANCE_RECORD,
        ];
    }
}
