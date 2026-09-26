import { Injectable, inject, signal } from '@angular/core';
import { HttpErrorResponse } from '@angular/common/http';
import { Observable } from 'rxjs';

import { ApiService, ApiResponse } from './api.service';

/**
 * Domain types for the Notifications & Operational Alerts workspace,
 * mirroring the backend NotificationResource exactly
 * (id, type, title, message, data, is_read, read_at, created_at).
 */

/** The known backend notification types (backend Notification model constants). */
export type KnownNotificationType =
  | 'ticket.assigned'
  | 'ticket.status_changed'
  | 'maintenance.assigned'
  | 'maintenance.approved'
  | 'maintenance.completed'
  | 'asset.assigned'
  | 'asset.returned';

/** The type is a free string so unknown future types render gracefully. */
export type NotificationType = KnownNotificationType | (string & {});

/** Payload carried by a notification; keyed by the backend domain services. */
export interface NotificationData {
  ticket_id?: number | string;
  ticket_number?: string;
  status?: string;
  maintenance_request_id?: number | string;
  asset_id?: number | string;
  assignment_id?: number | string;
  [key: string]: unknown;
}

/** Mirrors the backend NotificationResource. */
export interface NexoraNotification {
  id: number;
  type: NotificationType;
  title: string;
  message: string;
  data: NotificationData;
  is_read: boolean;
  read_at: string | null;
  created_at: string;
}

export interface NotificationPagination {
  items: NexoraNotification[];
  pagination: {
    current_page: number;
    per_page: number;
    total: number;
    last_page: number;
  };
}

export interface NotificationListFilters {
  /** 'true' = read, 'false' = unread. Omit to show both. */
  read?: boolean;
  /** Exact backend type (e.g. 'ticket.assigned'). Omit to show all. */
  type?: string;
  page?: number;
  per_page?: number;
}

/** User-friendly error from the notifications API. */
export interface NotificationErrorInfo {
  status: number | null;
  message: string;
  fieldErrors: Record<string, string>;
}

/**
 * Translate an HTTP failure from the notifications API into safe,
 * user-facing text plus (for 422 validation) per-field messages.
 * Backend internals are never surfaced verbatim.
 */
export function mapNotificationError(error: unknown): NotificationErrorInfo {
  const info: NotificationErrorInfo = {
    status: null,
    message: 'Something went wrong. Please try again.',
    fieldErrors: {},
  };

  if (!(error instanceof HttpErrorResponse)) {
    return info;
  }

  info.status = error.status;
  const body = error.error as { message?: string; errors?: Record<string, string[]> } | null;

  if (error.status === 422) {
    if (body?.errors) {
      for (const [field, fieldMessages] of Object.entries(body.errors)) {
        if (fieldMessages?.length) {
          info.fieldErrors[field] = fieldMessages[0];
        }
      }
      info.message = 'Please check the highlighted fields.';
      return info;
    }
    const message = body?.message?.trim();
    if (message) {
      info.message = message;
    }
    return info;
  }

  const messages: Record<number, string> = {
    401: 'Your session has expired. Please sign in again.',
    403: 'This action is unauthorized.',
    404: 'The notification no longer exists.',
    409: body?.message ?? 'This notification cannot be changed right now.',
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

/** Icon and label metadata for each known notification type. */
export interface NotificationTypeMeta {
  label: string;
  icon: string;
  /** Accent tone used for the icon chip. */
  tone: 'info' | 'warning' | 'neutral';
}

const TICKET_META: NotificationTypeMeta = { label: 'Ticket', icon: 'ticket-outline', tone: 'info' };
const MAINTENANCE_META: NotificationTypeMeta = { label: 'Maintenance', icon: 'construct-outline', tone: 'warning' };
const ASSET_META: NotificationTypeMeta = { label: 'Asset', icon: 'cube-outline', tone: 'neutral' };

/** Lookup for the known backend notification types (order is display order). */
export const NOTIFICATION_TYPE_META: Record<string, NotificationTypeMeta> = {
  'ticket.assigned': { ...TICKET_META, label: 'Ticket assigned' },
  'ticket.status_changed': { ...TICKET_META, label: 'Ticket updated' },
  'maintenance.assigned': { ...MAINTENANCE_META, label: 'Maintenance assigned' },
  'maintenance.approved': { ...MAINTENANCE_META, label: 'Maintenance approved' },
  'maintenance.completed': { ...MAINTENANCE_META, label: 'Maintenance completed' },
  'asset.assigned': { ...ASSET_META, label: 'Asset assigned' },
  'asset.returned': { ...ASSET_META, label: 'Asset returned' },
};

/** Icon + label metadata for a notification type (unknown types degrade gracefully). */
export function notificationTypeMeta(type: string): NotificationTypeMeta {
  return NOTIFICATION_TYPE_META[type] ?? { label: 'Alert', icon: 'notifications-outline', tone: 'info' };
}

export interface NotificationTypeOption {
  value: string;
  label: string;
}

export interface NotificationTypeGroup {
  group: string;
  options: NotificationTypeOption[];
}

/** The type-filter options, grouped by domain, mapping to backend type values exactly. */
export function notificationTypeGroups(): NotificationTypeGroup[] {
  const groupTypes: Array<[string, KnownNotificationType[]]> = [
    ['Ticket', ['ticket.assigned', 'ticket.status_changed']],
    ['Maintenance', ['maintenance.assigned', 'maintenance.approved', 'maintenance.completed']],
    ['Asset', ['asset.assigned', 'asset.returned']],
  ];
  return groupTypes.map(([group, types]) => ({
    group,
    options: types.map(type => ({ value: type, label: NOTIFICATION_TYPE_META[type]?.label ?? type })),
  }));
}

const MINUTE = 60_000;
const DAY = 24 * 60 * MINUTE;

/**
 * Compact relative timestamp ("Just now", "5 min ago", "2 days ago"); after a
 * week falls back to an absolute date so old notifications stay readable.
 * The `now` parameter is injectable for deterministic tests.
 */
export function formatNotificationTime(iso: string | null | undefined, now = Date.now()): string {
  if (!iso) return '';
  const time = new Date(iso).getTime();
  if (Number.isNaN(time)) return '';

  const diff = now - time;
  if (diff < MINUTE) return 'Just now';
  if (diff < 60 * MINUTE) {
    const minutes = Math.floor(diff / MINUTE);
    return `${minutes} min${minutes === 1 ? '' : 's'} ago`;
  }
  if (diff < DAY) {
    const hours = Math.floor(diff / (60 * MINUTE));
    return `${hours} hr${hours === 1 ? '' : 's'} ago`;
  }
  if (diff < 7 * DAY) {
    const days = Math.floor(diff / DAY);
    return `${days} day${days === 1 ? '' : 's'} ago`;
  }
  return new Date(time).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
}

/**
 * NotificationService is the single frontend client for the backend
 * notifications domain. Read state is always backend-authoritative
 * (is_read / read_at come from the API; the frontend never derives counts).
 */
@Injectable({ providedIn: 'root' })
export class NotificationService {
  private readonly api = inject(ApiService);

  listNotifications(filters: NotificationListFilters = {}): Observable<ApiResponse<NotificationPagination>> {
    return this.api.get<NotificationPagination>('/notifications', this.cleanParams(filters));
  }

  getNotification(id: number): Observable<ApiResponse<NexoraNotification>> {
    return this.api.get<NexoraNotification>(`/notifications/${id}`);
  }

  getUnreadCount(): Observable<ApiResponse<{ count: number }>> {
    return this.api.get<{ count: number }>('/notifications/unread-count');
  }

  markAsRead(id: number): Observable<ApiResponse<NexoraNotification>> {
    return this.api.post<NexoraNotification>(`/notifications/${id}/read`, {});
  }

  markAllAsRead(): Observable<ApiResponse<{ count: number }>> {
    return this.api.post<{ count: number }>('/notifications/read-all', {});
  }

  private cleanParams(filters: NotificationListFilters): Record<string, string | number | boolean> {
    const out: Record<string, string | number | boolean> = {};
    for (const [key, value] of Object.entries(filters)) {
      if (value !== undefined && value !== null && value !== '') {
        out[key] = value as string | number | boolean;
      }
    }
    return out;
  }
}

/**
 * NotificationInboxService holds the application-wide inbox state shared by
 * the shell bell and the inbox page: the unread count (always from the
 * backend endpoint, never derived) and its loading flag. State is per-session;
 * `reset()` must be called when the session ends so no data crosses users.
 */
@Injectable({ providedIn: 'root' })
export class NotificationInboxService {
  private readonly api = inject(NotificationService);

  /** Unread count from GET /notifications/unread-count; 0 when signed out. */
  readonly unreadCount = signal(0);

  /** True while a count refresh is in flight (prevents overlapping calls). */
  readonly unreadLoading = signal(false);

  /** Load the unread count. Failures are non-fatal and leave the last value. */
  loadUnreadCount(): void {
    if (this.unreadLoading()) return;
    this.unreadLoading.set(true);
    this.api.getUnreadCount().subscribe({
      next: (res) => {
        this.unreadLoading.set(false);
        if (res.success) {
          this.unreadCount.set(res.data.count);
        }
      },
      error: () => {
        this.unreadLoading.set(false);
      },
    });
  }

  /** Alias used after read mutations so the badge stays backend-accurate. */
  refreshUnreadCount(): void {
    this.loadUnreadCount();
  }

  /** Session isolation: drop cached state on logout. */
  reset(): void {
    this.unreadCount.set(0);
    this.unreadLoading.set(false);
  }
}