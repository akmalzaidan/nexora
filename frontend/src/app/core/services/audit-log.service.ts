import { Injectable, inject } from '@angular/core';
import { HttpErrorResponse } from '@angular/common/http';
import { Observable } from 'rxjs';

import { ApiService, ApiResponse } from './api.service';

/**
 * Domain types for the Audit Logs & Governance workspace, mirroring the
 * backend AuditLogResource exactly (Phase 20A contract — docs/api/README.md):
 *
 *   id, actor {id, name}|null, action, resource {type, id}, description,
 *   old_values, new_values, ip_address, user_agent, created_at
 *
 * Nothing is invented: fields the backend does not return do not exist here.
 */

/** Mirrors the backend actor object (compact; never email or credentials). */
export interface AuditActor {
  id: number;
  name: string;
}

/** Mirrors the backend resource object (controlled-vocabulary type + id). */
export interface AuditResource {
  type: string;
  id: number | null;
}

/** Arbitrary JSON value maps (old_values / new_values). */
export type AuditValueMap = Record<string, unknown> | null;

/** Mirrors the backend AuditLogResource. */
export interface AuditLog {
  id: number;
  actor: AuditActor | null;
  action: string;
  resource: AuditResource;
  description: string | null;
  old_values: AuditValueMap;
  new_values: AuditValueMap;
  ip_address: string | null;
  user_agent: string | null;
  created_at: string | null;
}

export interface AuditPagination {
  items: AuditLog[];
  pagination: {
    current_page: number;
    per_page: number;
    total: number;
    last_page: number;
  };
}

/** Filters supported by GET /audit-logs (Phase 20A). Anything else is dropped. */
export interface AuditFilters {
  actor_id?: number | string;
  action?: string;
  resource_type?: string;
  resource_id?: number | string;
  /** Inclusive calendar-day lower bound (Y-m-d). Sent only with `to`. */
  from?: string;
  /** Inclusive calendar-day upper bound (Y-m-d). Sent only with `from`. */
  to?: string;
  page?: number;
  per_page?: number;
}

/** User-friendly error from the audit API. */
export interface AuditErrorInfo {
  status: number | null;
  message: string;
}

/**
 * Translate an HTTP failure from the audit API into safe, user-facing text.
 * Backend internals are never surfaced verbatim.
 */
export function mapAuditError(error: unknown): AuditErrorInfo {
  const info: AuditErrorInfo = {
    status: null,
    message: 'Something went wrong. Please try again.',
  };

  if (!(error instanceof HttpErrorResponse)) {
    return info;
  }

  info.status = error.status;
  const body = error.error as { message?: string } | null;

  const messages: Record<number, string> = {
    401: 'Your session has expired. Please sign in again.',
    403: 'You do not have access to governance audit logs.',
    404: 'This audit event no longer exists.',
    422: 'The requested date range or filters are invalid.',
    429: 'Too many attempts. Please try again later.',
  };

  if (error.status in messages) {
    info.message = messages[error.status];
    return info;
  }

  if (error.status === 0) {
    info.message = 'Unable to connect to the server.';
    return info;
  }

  if (error.status >= 500) {
    info.message = 'Something went wrong. Please try again.';
    return info;
  }

  if (body?.message) {
    info.message = body.message;
  }

  return info;
}

// ─── Presentation labels (humanized display only; raw values stay raw) ──────

/** The known backend actions (App\Support\Audit\AuditAction). Order is display order. */
export const AUDIT_ACTIONS: readonly string[] = [
  'created',
  'updated',
  'deleted',
  'status_changed',
  'assigned',
  'returned',
  'registered',
  'logged_in',
  'logged_out',
];

/** The known backend resource types (App\Support\Audit\AuditResourceType). */
export const AUDIT_RESOURCE_TYPES: readonly string[] = [
  'user',
  'asset',
  'asset_assignment',
  'item',
  'item_category',
  'warehouse',
  'stock_movement',
  'ticket',
  'maintenance_request',
  'maintenance_record',
];

/**
 * Humanize a snake_case backend value ("status_changed" → "Status changed").
 * Used as the display layer for known values and as a safe fallback for
 * unknown future values — the raw value is always what is sent to the API.
 */
export function humanizeAuditValue(value: string): string {
  const words = value.split('_').filter(Boolean);
  if (words.length === 0) return value;
  return words
    .map((word, index) => (index === 0 ? capitalize(word) : word.toLowerCase()))
    .join(' ');
}

function capitalize(word: string): string {
  return word.charAt(0).toUpperCase() + word.slice(1).toLowerCase();
}

/** Compact audit timestamp ("26 Sep, 16:42"); falls back to the raw value. */
export function formatAuditTime(iso: string | null | undefined): string {
  if (!iso) return '—';
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return iso;
  const day = date.toLocaleDateString(undefined, { day: 'numeric', month: 'short' });
  const time = date.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit', hour12: false });
  return `${day}, ${time}`;
}

/** Structured before/after diff row computed from old_values + new_values. */
export interface AuditChangeRow {
  field: string;
  old: string;
  new: string;
}

/** Render any JSON value as compact display text (never raw JSON dumps). */
export function auditValueText(value: unknown): string {
  if (value === null || value === undefined) return '—';
  if (typeof value === 'string') return value === '' ? '—' : value;
  if (typeof value === 'number' || typeof value === 'boolean') return String(value);
  try {
    return JSON.stringify(value);
  } catch {
    return String(value);
  }
}

/**
 * Compute the changed-field rows for an event that carries both sides
 * ("Changed fields"). Fields whose value did not change are omitted; fields
 * only present on one side render "—" on the other.
 */
export function auditChangedRows(oldValues: AuditValueMap, newValues: AuditValueMap): AuditChangeRow[] {
  if (!oldValues || !newValues) return [];
  const rows: AuditChangeRow[] = [];
  const fields = Array.from(new Set([...Object.keys(oldValues), ...Object.keys(newValues)])).sort();
  for (const field of fields) {
    const oldRaw = oldValues[field];
    const newRaw = newValues[field];
    if (JSON.stringify(oldRaw ?? null) === JSON.stringify(newRaw ?? null)) {
      continue;
    }
    rows.push({ field: humanizeAuditValue(field), old: auditValueText(oldRaw), new: auditValueText(newRaw) });
  }
  return rows;
}

/** A deep-link target for a related record (verified workspace routes only). */
export interface AuditNavigationTarget {
  path: string;
  queryParams: { selected: number };
}

/**
 * Map a resource type + id to the related workspace. Only mappings whose
 * destination route actually supports `?selected=` AND whose id identifies
 * that destination's record are provided. Notably `asset_assignment` is NOT
 * mapped to /assets: an assignment id is not an asset id. Unknown resource
 * types resolve to null ("No navigation available").
 */
export function auditResourceTarget(type: string, id: number | null): AuditNavigationTarget | null {
  if (id === null || !Number.isInteger(id) || id <= 0) {
    return null;
  }
  switch (type) {
    case 'ticket':
      return { path: '/requests', queryParams: { selected: id } };
    case 'maintenance_request':
      return { path: '/maintenance', queryParams: { selected: id } };
    case 'asset':
      return { path: '/assets', queryParams: { selected: id } };
    case 'item':
      return { path: '/inventory', queryParams: { selected: id } };
    default:
      return null;
  }
}

/**
 * AuditLogService is the single frontend client for the backend audit
 * governance domain. The trail is immutable: this service deliberately offers
 * only list and get — no create, update, or delete exists here or on the
 * backend.
 */
@Injectable({ providedIn: 'root' })
export class AuditLogService {
  private readonly api = inject(ApiService);

  getAuditLogs(filters: AuditFilters = {}): Observable<ApiResponse<AuditPagination>> {
    return this.api.get<AuditPagination>('/audit-logs', this.cleanParams(filters));
  }

  getAuditLog(id: number): Observable<ApiResponse<AuditLog>> {
    return this.api.get<AuditLog>(`/audit-logs/${id}`);
  }

  /** Drop undefined/null/'' params so the API only receives real filters. */
  private cleanParams(filters: AuditFilters): Record<string, string | number | boolean> {
    const out: Record<string, string | number | boolean> = {};
    for (const [key, value] of Object.entries(filters)) {
      if (value !== undefined && value !== null && value !== '') {
        out[key] = value as string | number | boolean;
      }
    }
    return out;
  }
}
