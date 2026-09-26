<?php

namespace App\Support\Audit;

/**
 * Controlled audit action vocabulary (Phase 20A).
 *
 * Every value written to `audit_logs.action` MUST come from this list so the
 * governance trail stays queryable and cannot drift into free text. Actions
 * describe governance-relevant mutations only — reads, comments, notification
 * reads and report/command-center views are deliberately not audited.
 *
 * Keep values lowercase snake_case; the API contract exposes them as-is.
 */
final class AuditAction
{
    public const CREATED = 'created';

    public const UPDATED = 'updated';

    public const DELETED = 'deleted';

    public const STATUS_CHANGED = 'status_changed';

    public const ASSIGNED = 'assigned';

    public const RETURNED = 'returned';

    public const REGISTERED = 'registered';

    public const LOGGED_IN = 'logged_in';

    public const LOGGED_OUT = 'logged_out';

    /**
     * All actions that may appear in `audit_logs.action`, used to validate the
     * `action` filter on the read API.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::CREATED,
            self::UPDATED,
            self::DELETED,
            self::STATUS_CHANGED,
            self::ASSIGNED,
            self::RETURNED,
            self::REGISTERED,
            self::LOGGED_IN,
            self::LOGGED_OUT,
        ];
    }
}
