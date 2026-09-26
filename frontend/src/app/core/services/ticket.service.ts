import { Injectable, inject } from '@angular/core';
import { HttpErrorResponse } from '@angular/common/http';
import { Observable } from 'rxjs';

import { ApiService, ApiResponse } from './api.service';

/**
 * Domain types for the Helpdesk / Request Management workspace, mirroring the
 * backend resources exactly (TicketResource, TicketCategoryResource,
 * TicketCommentResource, TicketHistoryResource, UserResource).
 */

export type TicketStatus = 'OPEN' | 'IN_PROGRESS' | 'RESOLVED' | 'CLOSED';
export type TicketPriority = 'LOW' | 'MEDIUM' | 'HIGH' | 'URGENT';
export type TicketAction = 'CREATED' | 'UPDATED' | 'STATUS_CHANGED' | 'ASSIGNMENT_CHANGED';

/** Reference shape shared by category / department / location lookups. */
export interface NamedRef {
  id: number;
  name: string;
  code: string;
}

/** Mirrors the backend UserResource. */
export interface TicketUser {
  id: number;
  name: string;
  email: string;
  role: { id: number; name: string; slug: string } | null;
  department: NamedRef | null;
  is_active: boolean;
  created_at: string | null;
}

/** Mirrors the backend TicketResource index/show shape. */
export interface Ticket {
  id: number;
  ticket_number: string;
  title: string;
  description: string | null;
  category: NamedRef | null;
  requester: TicketUser | null;
  assignee: TicketUser | null;
  department: NamedRef | null;
  location: NamedRef | null;
  priority: TicketPriority;
  status: TicketStatus;
  created_at: string | null;
  updated_at: string | null;
}

/** Mirrors the backend TicketCategoryResource. */
export interface TicketCategory {
  id: number;
  name: string;
  code: string;
  description: string | null;
  tickets_count: number | null;
  created_at: string | null;
  updated_at: string | null;
}

/** Mirrors the backend TicketCommentResource. */
export interface TicketComment {
  id: number;
  comment: string;
  is_internal: boolean;
  user: TicketUser | null;
  created_at: string | null;
  updated_at: string | null;
}

/** Mirrors the backend TicketHistoryResource (oldest-first). */
export interface TicketHistory {
  id: number;
  action: TicketAction;
  old_status: TicketStatus | null;
  new_status: TicketStatus | null;
  notes: string | null;
  user: TicketUser | null;
  created_at: string | null;
}

export interface Paginated<T> {
  items: T[];
  pagination: {
    current_page: number;
    per_page: number;
    total: number;
    last_page: number;
  };
}

export interface TicketListFilters {
  search?: string;
  status?: TicketStatus;
  priority?: TicketPriority;
  category_id?: number | string;
  assigned_to?: number | string;
  department_id?: number | string;
  location_id?: number | string;
  sort?: 'created_at' | 'updated_at' | 'ticket_number';
  direction?: 'asc' | 'desc';
  page?: number;
  per_page?: number;
}

export interface TicketCategoryListFilters {
  search?: string;
  sort?: string;
  direction?: 'asc' | 'desc';
  page?: number;
  per_page?: number;
}

export interface TicketPayload {
  title: string;
  description: string;
  category_id?: number | null;
  department_id?: number | null;
  location_id?: number | null;
  priority?: TicketPriority | null;
}

export interface TicketUpdatePayload {
  title?: string;
  description?: string;
  category_id?: number | null;
  department_id?: number | null;
  location_id?: number | null;
  priority?: TicketPriority | null;
  status?: TicketStatus;
  assigned_to?: number | null;
}

export interface TicketCategoryPayload {
  name: string;
  code: string;
  description?: string | null;
}

export interface TicketCommentPayload {
  comment: string;
  is_internal?: boolean;
}

/** User-friendly error from a helpdesk API call. */
export interface TicketErrorInfo {
  status: number | null;
  message: string;
  fieldErrors: Record<string, string>;
  /** True when a ticket fetch was rejected due to permissions (do not leak metadata). */
  accessDenied: boolean;
  /** True when a status change was rejected by the backend workflow. */
  invalidTransition: boolean;
}

/**
 * Translate an HTTP failure from the helpdesk APIs into safe, user-facing
 * text and (for 422 validation) per-field messages. Backend internals are
 * never surfaced verbatim. Business rejections that users can act on
 * (invalid status transitions, assignment failures) carry their message
 * through with flags the workspace UI uses to react.
 */
export function mapTicketError(error: unknown): TicketErrorInfo {
  const info: TicketErrorInfo = {
    status: null,
    message: 'Something went wrong. Please try again.',
    fieldErrors: {},
    accessDenied: false,
    invalidTransition: false,
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
      info.invalidTransition = message.toLowerCase().includes('invalid status transition');
    }
    return info;
  }

  if (error.status === 403) {
    const message = body?.message?.trim();
    if (message?.toLowerCase().includes('access')) {
      info.message = 'You do not have access to this request.';
      info.accessDenied = true;
      return info;
    }
    info.message = 'This action is unauthorized.';
    return info;
  }

  const messages: Record<number, string> = {
    404: 'The requested record no longer exists.',
    409: body?.message ?? 'This record is still in use and cannot be changed.',
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

/**
 * TicketService is the single frontend client for the backend helpdesk
 * domain: ticket categories, tickets, ticket comments, and the ticket
 * history timeline. Ticket status is never derived client-side — the backend
 * is authoritative and returns it with every ticket.
 */
@Injectable({ providedIn: 'root' })
export class TicketService {
  private readonly api = inject(ApiService);

  // ─── Categories ────────────────────────────────────────────────────────────

  listCategories(filters: TicketCategoryListFilters = {}): Observable<ApiResponse<Paginated<TicketCategory>>> {
    return this.api.get<Paginated<TicketCategory>>('/ticket-categories', this.cleanParams(filters));
  }

  getCategory(id: number): Observable<ApiResponse<TicketCategory>> {
    return this.api.get<TicketCategory>(`/ticket-categories/${id}`);
  }

  createCategory(payload: TicketCategoryPayload): Observable<ApiResponse<TicketCategory>> {
    return this.api.post<TicketCategory>('/ticket-categories', payload);
  }

  updateCategory(id: number, payload: TicketCategoryPayload): Observable<ApiResponse<TicketCategory>> {
    return this.api.put<TicketCategory>(`/ticket-categories/${id}`, payload);
  }

  deleteCategory(id: number): Observable<ApiResponse<null>> {
    return this.api.delete<null>(`/ticket-categories/${id}`);
  }

  // ─── Tickets ───────────────────────────────────────────────────────────────

  listTickets(filters: TicketListFilters = {}): Observable<ApiResponse<Paginated<Ticket>>> {
    return this.api.get<Paginated<Ticket>>('/tickets', this.cleanParams(filters));
  }

  getTicket(id: number): Observable<ApiResponse<Ticket>> {
    return this.api.get<Ticket>(`/tickets/${id}`);
  }

  createTicket(payload: TicketPayload): Observable<ApiResponse<Ticket>> {
    return this.api.post<Ticket>('/tickets', payload);
  }

  updateTicket(id: number, payload: TicketUpdatePayload): Observable<ApiResponse<Ticket>> {
    return this.api.put<Ticket>(`/tickets/${id}`, payload);
  }

  // ─── Comments ──────────────────────────────────────────────────────────────

  listComments(ticketId: number, perPage = 100): Observable<ApiResponse<Paginated<TicketComment>>> {
    return this.api.get<Paginated<TicketComment>>(`/tickets/${ticketId}/comments`, { per_page: perPage });
  }

  createComment(ticketId: number, payload: TicketCommentPayload): Observable<ApiResponse<TicketComment>> {
    return this.api.post<TicketComment>(`/tickets/${ticketId}/comments`, payload);
  }

  // ─── History ───────────────────────────────────────────────────────────────

  /** Oldest-first timeline as returned by the backend. Never reversed client-side. */
  listHistory(ticketId: number, perPage = 100): Observable<ApiResponse<Paginated<TicketHistory>>> {
    return this.api.get<Paginated<TicketHistory>>(`/tickets/${ticketId}/history`, { per_page: perPage });
  }

  private cleanParams(filters: object): Record<string, string | number | boolean> {
    const out: Record<string, string | number | boolean> = {};
    for (const [key, value] of Object.entries(filters)) {
      if (value !== undefined && value !== null && value !== '') {
        out[key] = value as string | number | boolean;
      }
    }
    return out;
  }
}