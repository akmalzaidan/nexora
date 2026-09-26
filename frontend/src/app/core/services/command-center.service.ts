import { Injectable, inject } from '@angular/core';
import { HttpErrorResponse } from '@angular/common/http';
import { Observable } from 'rxjs';

import { ApiService, ApiResponse } from './api.service';

/**
 * Command Center domain types for the operational workspace.
 *
 * These mirror the Phase 19A `GET /api/v1/command-center` payload exactly (see
 * `backend/app/Services/CommandCenter/` and `docs/api/README.md`). Nothing is
 * invented, renamed, or derived client-side: the backend is the only authority
 * for these numbers, so the workspace renders them as received.
 *
 * Four contract rules shape the whole workspace:
 *
 *  1. **Sections are optional.** A domain the caller may not read is *omitted*
 *     from the payload, not sent as zeroes. Every domain group below is
 *     therefore optional and its absence is meaningful — it renders nothing
 *     rather than "0".
 *  2. **Current state is never mixed with dated history.** `snapshot` is
 *     undated; `recent_activity` is an event log. They sit side by side and are
 *     never summed, averaged, or turned into a rate.
 *  3. **A fact is not a judgement.** There is no health, risk, score, trend
 *     verdict, or ranking field in the contract, so none can be displayed.
 *  4. **Counts are complete, lists are bounded.** Each queue reports the full
 *     `count` and only the first `limit` items, so a truncated list never hides
 *     the size of the backlog.
 */

/**
 * The exact `limit` bounds the backend enforces. Sending nothing is valid and
 * yields the backend default, which is echoed back on every response.
 */
export const COMMAND_CENTER_DEFAULT_LIMIT = 10;
export const COMMAND_CENTER_MAX_LIMIT = 50;

/** Asset counters, present only when the caller holds `view_assets`. */
export interface CommandCenterAssetSnapshot {
  total: number;
  active: number;
  in_maintenance: number;
  unassigned: number;
}

/** Inventory counters, present only when the caller holds `view_inventory`. */
export interface CommandCenterInventorySnapshot {
  item_count: number;
  warehouse_count: number;
  /** Signed stock-journal balance, never a row count. */
  stock_quantity: number;
}

/** Ticket counters, scoped to what the caller may read. */
export interface CommandCenterTicketSnapshot {
  total: number;
  active: number;
  unassigned: number;
}

/** Maintenance counters, scoped to what the caller may read. */
export interface CommandCenterMaintenanceSnapshot {
  total: number;
  active: number;
  unassigned: number;
  awaiting_approval: number;
}

/**
 * Current state. `assets`, `inventory`, `tickets` and `maintenance` are omitted
 * for a caller without the matching domain permission. `notifications` is
 * always present: it is the caller's own inbox and is never scoped away.
 */
export interface CommandCenterSnapshot {
  assets?: CommandCenterAssetSnapshot;
  inventory?: CommandCenterInventorySnapshot;
  tickets?: CommandCenterTicketSnapshot;
  maintenance?: CommandCenterMaintenanceSnapshot;
  notifications: { unread_count: number };
}

/**
 * The record types the API can return in a queue row. `ticket` and
 * `maintenance_request` carry the primary key of that record, which is what
 * makes a row navigable; `asset_assignment` carries the *assignment* id, which
 * no existing workspace selects by, so those rows stay read-only.
 */
export type CommandCenterQueueItemType = 'ticket' | 'maintenance_request' | 'asset_assignment';

/** One row of an operational queue. */
export interface CommandCenterQueueItem {
  id: number;
  type: CommandCenterQueueItemType;
  /** `null` when the source record has no server-side reference number. */
  reference: string | null;
  title: string;
  status: string;
  created_at: string | null;
}

/**
 * A bounded operational queue: the complete waiting count, the applied limit,
 * and the newest rows up to that limit.
 */
export interface CommandCenterQueue<T extends CommandCenterQueueItem = CommandCenterQueueItem> {
  count: number;
  limit: number;
  items: T[];
}

/** The event sources the API reads. */
export type CommandCenterActivityType = 'asset' | 'stock_movement' | 'ticket' | 'maintenance';

/**
 * One recent operational event.
 *
 * `action` is the source's own stored vocabulary and is never normalized into a
 * shared event type, and `record_id` is the id of the *event row* (a history or
 * work record), not of the asset, ticket, or request the event is about. That is
 * why activity is read-only in this workspace: it does not carry enough
 * information to address an existing destination, and guessing one would send a
 * user to the wrong record.
 */
export interface CommandCenterActivityItem {
  type: CommandCenterActivityType;
  action: string;
  occurred_at: string;
  record_id: number;
  reference: string | null;
  label: string | null;
  status: string | null;
  quantity: number | null;
  old_status: string | null;
  new_status: string | null;
}

/** Newest-first bounded activity list, in the order the API returned it. */
export interface CommandCenterActivity {
  limit: number;
  items: CommandCenterActivityItem[];
}

/** The operational queues the caller is allowed to see. */
export interface CommandCenterQueues {
  unassigned_tickets?: CommandCenterQueue;
  unassigned_maintenance_requests?: CommandCenterQueue;
  pending_asset_assignments?: CommandCenterQueue;
}

/** The complete `data` payload of the endpoint. */
export interface CommandCenterPayload {
  generated_at: string;
  snapshot: CommandCenterSnapshot;
  queues: CommandCenterQueues;
  recent_activity: CommandCenterActivity;
}

/** The queue keys the API can return, in presentation order. */
export type CommandCenterQueueKey = keyof CommandCenterQueues;

/** Normalized failure information for the workspace. */
export interface CommandCenterErrorInfo {
  status: number | null;
  message: string;
  /** True for a 403, which the workspace renders as the Access Restricted state. */
  accessDenied: boolean;
  fieldErrors: Record<string, string>;
}

/**
 * Translate an HTTP failure from the Command Center endpoint into safe,
 * user-facing text. Backend internals are never surfaced verbatim; the copy
 * matches the existing report/notification error patterns so the shell feels
 * the same everywhere.
 */
export function mapCommandCenterError(error: unknown): CommandCenterErrorInfo {
  const info: CommandCenterErrorInfo = {
    status: null,
    message: 'Unable to load the Command Center.',
    accessDenied: false,
    fieldErrors: {},
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
    }

    info.message = 'The Command Center request was rejected. Refresh to try again.';
    return info;
  }

  if (error.status === 403) {
    info.accessDenied = true;
    info.message = 'You do not have access to the Command Center.';
    return info;
  }

  const messages: Record<number, string> = {
    401: 'Your session has expired. Please sign in again.',
    404: 'The Command Center is not available.',
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
    info.message = 'The server could not build the snapshot. Please try again.';
    return info;
  }

  if (body?.message) {
    info.message = body.message;
  }

  return info;
}

/**
 * CommandCenterService is the single frontend client for the Phase 19A
 * read-only operational endpoint.
 *
 * Every method is a plain GET through `ApiService`: there is no write path, no
 * polling, no websocket, and no caching beyond the page's own signal state. The
 * workspace deliberately never calls the domain list endpoints to rebuild a
 * figure this endpoint already returns — one request is the whole page.
 */
@Injectable({ providedIn: 'root' })
export class CommandCenterService {
  private readonly api = inject(ApiService);

  /**
   * Current operational state, queues, and recent activity.
   *
   * `limit` bounds the queue item lists and the activity list. No date
   * parameters are ever sent: the endpoint is a current-state surface and
   * rejects `from`/`to` outright.
   */
  getSnapshot(limit?: number): Observable<ApiResponse<CommandCenterPayload>> {
    const params: Record<string, string | number> = {};

    if (limit !== undefined && limit !== null) {
      params['limit'] = limit;
    }

    return this.api.get<CommandCenterPayload>('/command-center', params);
  }
}
