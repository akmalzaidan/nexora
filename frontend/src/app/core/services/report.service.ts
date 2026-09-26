import { Injectable, inject } from '@angular/core';
import { HttpErrorResponse } from '@angular/common/http';
import { Observable } from 'rxjs';

import { ApiService, ApiResponse } from './api.service';

/**
 * Report domain types for the Reports workspace.
 *
 * These mirror the Phase 18A read-only report payloads exactly (see
 * `backend/app/Services/Reports/` and `docs/api/README.md`). Nothing is
 * invented and nothing is derived: the backend is the only authority for these
 * numbers, so the workspace renders them as received.
 *
 * Two rules from the backend contract shape the whole workspace:
 *  1. `current` sections are an undated snapshot; `period` is `null` unless a
 *     range was requested. They are never mixed.
 *  2. A report is a fact, never a judgement — there is no health, risk, or
 *     score field to render, so none is displayed.
 */

/** Inclusive `Y-m-d` UTC calendar-day bounds, echoed back by the backend. */
export interface ReportPeriod {
  from: string;
  to: string;
}

/**
 * The one contract every breakdown in the report API shares: a value taken
 * from a fixed vocabulary, paired with its count.
 *
 * The API names the value field after the dimension it aggregates
 * (`status`, `priority`, `category_id`, …) and returns it untouched, so the key
 * is a type parameter rather than a renamed `value` property. `TId` is `string`
 * for status-like dimensions and `number` for reference ids.
 */
export type ReportBreakdown<TKey extends string, TId = string> = { count: number } & Record<TKey, TId>;

/** `{ status, count }` over the exact stored status values. */
export type StatusBreakdown = ReportBreakdown<'status'>;

/** `{ priority, count }` — a priority category, not a performance signal. */
export type PriorityBreakdown = ReportBreakdown<'priority'>;

/** Assets per category, as labelled by `asset_categories.name`. */
export type CategoryCount = ReportBreakdown<'category_id', number> & { label: string };

/** Assets per location. Assets without a location are not represented here. */
export type LocationCount = ReportBreakdown<'location_id', number> & { label: string };

/** One day of a gap-filled daily series, as returned by the backend. */
export interface ReportDayBucket {
  date: string;
  count: number;
}

export interface ReportOverview {
  generated_at: string;
  assets: { total: number; by_status: StatusBreakdown[] };
  inventory: { item_count: number; warehouse_count: number; stock_quantity: number };
  tickets: { total: number; by_status: StatusBreakdown[] };
  maintenance: { total: number; by_status: StatusBreakdown[] };
}

export interface AssetReportCurrent {
  total: number;
  by_status: StatusBreakdown[];
  by_category: CategoryCount[];
  by_location: LocationCount[];
  assigned_count: number;
  unassigned_count: number;
  without_location_count: number;
}

export interface AssetReport {
  current: AssetReportCurrent;
  assignments: { total: number; by_status: StatusBreakdown[] };
  period: (ReportPeriod & {
    assets_created: number;
    assignments_assigned: number;
    assignments_returned: number;
  }) | null;
}

export interface InventoryReport {
  items: { count: number; category_count: number };
  warehouses: { count: number };
  current_stock: {
    total_quantity: number;
    by_warehouse: { warehouse_id: number; label: string; quantity: number }[];
    by_item: { limit: number; items: { item_id: number; quantity: number }[] };
  };
  period: (ReportPeriod & {
    movement_count: number;
    stock_in_total: number;
    stock_out_total: number;
  }) | null;
}

export interface TicketReport {
  current: {
    total: number;
    by_status: StatusBreakdown[];
    by_priority: PriorityBreakdown[];
    by_category: CategoryCount[];
  };
  period: (ReportPeriod & { created: number; trend: ReportDayBucket[] }) | null;
}

export interface MaintenanceReport {
  requests: { total: number; by_status: StatusBreakdown[]; by_priority: PriorityBreakdown[] };
  records: { count: number; costed_count: number; total_cost: string; average_cost: string | null };
  parts: {
    usage_count: number;
    top_items: {
      limit: number;
      items: { item_id: number; sku: string; label: string; quantity: number; usage_count: number }[];
    };
  };
  by_asset: {
    limit: number;
    items: { asset_id: number; asset_code: string; asset_name: string; count: number }[];
  };
  period: (ReportPeriod & { requested: number; approved: number; completed: number }) | null;
}

/** Optional query parameters accepted by the detail report endpoints. */
export interface ReportFilters {
  /** Inclusive `Y-m-d` start day. Must be sent together with `to`. */
  from?: string;
  /** Inclusive `Y-m-d` end day. Must be sent together with `from`. */
  to?: string;
  /** Ranking size, 1–100. Only bounds the scalable rankings. */
  limit?: number;
}

/** Maximum inclusive days one report period may span (backend contract). */
export const REPORT_MAX_PERIOD_DAYS = 366;

/** Default ranking size, matching the backend default. */
export const REPORT_DEFAULT_LIMIT = 10;

/** User-facing error from a report API call. */
export interface ReportErrorInfo {
  status: number | null;
  message: string;
  fieldErrors: Record<string, string>;
  /** True when the API refused the request for lack of `view_reports`. */
  accessDenied: boolean;
}

/**
 * Turn a `Y-m-d` day into a UTC timestamp, or `null` when the string is not a
 * real calendar day.
 *
 * `Date.parse()` alone is not enough: it silently rolls impossible days over
 * (`2026-02-31` becomes March 3rd) instead of rejecting them, so the parsed
 * date is compared back against the input to catch that rollover.
 */
function parseIsoDay(value: string): number | null {
  const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value);
  if (!match) {
    return null;
  }

  const year = Number(match[1]);
  const month = Number(match[2]) - 1;
  const day = Number(match[3]);
  const stamp = Date.UTC(year, month, day);
  const parsed = new Date(stamp);

  if (
    Number.isNaN(parsed.getTime()) ||
    parsed.getUTCFullYear() !== year ||
    parsed.getUTCMonth() !== month ||
    parsed.getUTCDate() !== day
  ) {
    return null;
  }

  return stamp;
}

/**
 * Validate a report period in the browser with the same rules the API
 * enforces, so an obviously invalid range is caught before a request is made.
 * The backend stays authoritative — a `422` is still surfaced verbatim.
 *
 * @returns an error message, or `null` when the range is valid (including the
 *          "neither bound set" state, which is a valid current-state report).
 */
export function validateReportPeriod(from: string, to: string): string | null {
  const start = from.trim();
  const end = to.trim();
  const hasFrom = start !== '';
  const hasTo = end !== '';

  if (hasFrom !== hasTo) {
    return 'Provide both a start and an end date, or neither.';
  }

  if (!hasFrom) {
    return null;
  }

  const startStamp = parseIsoDay(start);
  const endStamp = parseIsoDay(end);
  if (startStamp === null || endStamp === null) {
    return 'Dates must use the YYYY-MM-DD format.';
  }

  if (startStamp > endStamp) {
    return 'The start date must be on or before the end date.';
  }

  // Inclusive day count, computed from UTC calendar days so no timezone of the
  // viewing device can shift the boundary.
  const days = Math.round((endStamp - startStamp) / 86_400_000) + 1;

  if (days > REPORT_MAX_PERIOD_DAYS) {
    return `A report period may not exceed ${REPORT_MAX_PERIOD_DAYS} days.`;
  }

  return null;
}

/** Query params with empty values dropped, so no partial period is ever sent. */
function cleanParams(filters: ReportFilters): Record<string, string | number> {
  const params: Record<string, string | number> = {};
  const from = filters.from?.trim() ?? '';
  const to = filters.to?.trim() ?? '';

  if (from !== '' && to !== '') {
    params['from'] = from;
    params['to'] = to;
  }

  if (filters.limit !== undefined && filters.limit !== null) {
    params['limit'] = filters.limit;
  }

  return params;
}

/**
 * Translate an HTTP failure from the reports APIs into safe, user-facing text
 * and (for 422) per-field messages. Backend internals are never surfaced
 * verbatim; a 422 period rejection keeps its message so the user can act on it.
 */
export function mapReportError(error: unknown): ReportErrorInfo {
  const info: ReportErrorInfo = {
    status: null,
    message: 'Something went wrong. Please try again.',
    fieldErrors: {},
    accessDenied: false,
  };

  if (!(error instanceof HttpErrorResponse)) {
    return info;
  }

  info.status = error.status;
  const body = error.error as { message?: string; errors?: Record<string, string[]> } | null;

  if (error.status === 422) {
    if (body?.errors) {
      for (const [field, messages] of Object.entries(body.errors)) {
        if (messages?.length) {
          info.fieldErrors[field] = messages[0];
        }
      }
      info.message = 'Please check the report period.';
      return info;
    }

    const message = body?.message?.trim();
    if (message) {
      info.message = message;
    }
    return info;
  }

  if (error.status === 403) {
    info.accessDenied = true;
    info.message = 'You do not have access to operational reports.';
    return info;
  }

  const messages: Record<number, string> = {
    401: 'Your session has expired. Please sign in again.',
    404: 'This report is not available.',
    429: 'Too many requests. Please try again later.',
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

/**
 * ReportService is the single frontend client for the Phase 18A read-only
 * report endpoints.
 *
 * Every method is a plain GET through ApiService: there is no write path, no
 * polling, and no caching beyond the page's own signal state. The overview is
 * a current-state snapshot and therefore accepts no period — the workspace
 * keeps that distinction visible instead of sending a parameter the API would
 * reject.
 */
@Injectable({ providedIn: 'root' })
export class ReportService {
  private readonly api = inject(ApiService);

  /** Cross-module operational snapshot (no period). */
  getOverview(): Observable<ApiResponse<ReportOverview>> {
    return this.api.get<ReportOverview>('/reports/overview');
  }

  /** Asset inventory, assignment records, and period activity. */
  getAssets(filters: ReportFilters = {}): Observable<ApiResponse<AssetReport>> {
    return this.api.get<AssetReport>('/reports/assets', cleanParams(filters));
  }

  /** Catalog counts, current stock balances, and period movement activity. */
  getInventory(filters: ReportFilters = {}): Observable<ApiResponse<InventoryReport>> {
    return this.api.get<InventoryReport>('/reports/inventory', cleanParams(filters));
  }

  /** Ticket state, breakdowns, and the period creation trend. */
  getTickets(filters: ReportFilters = {}): Observable<ApiResponse<TicketReport>> {
    return this.api.get<TicketReport>('/reports/tickets', cleanParams(filters));
  }

  /** Maintenance requests, recorded cost, parts trace, and period lifecycle. */
  getMaintenance(filters: ReportFilters = {}): Observable<ApiResponse<MaintenanceReport>> {
    return this.api.get<MaintenanceReport>('/reports/maintenance', cleanParams(filters));
  }
}
