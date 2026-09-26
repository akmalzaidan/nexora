import { Injectable, inject } from '@angular/core';
import { HttpErrorResponse } from '@angular/common/http';
import { Observable } from 'rxjs';

import { ApiService, ApiResponse } from './api.service';

/**
 * Domain types for the Maintenance Management workspace, mirroring the
 * backend resources exactly (MaintenanceRequestResource,
 * MaintenanceRecordResource, MaintenancePartResource, UserResource).
 */

export type MaintenanceStatus = 'REQUESTED' | 'APPROVED' | 'IN_PROGRESS' | 'COMPLETED' | 'CANCELLED';
export type MaintenancePriority = 'LOW' | 'MEDIUM' | 'HIGH' | 'URGENT';

/** Mirrors the backend UserResource (requester / assignee / technician). */
export interface MaintenanceUser {
  id: number;
  name: string;
  email: string;
  role: { id: number; name: string; slug: string } | null;
  department: { id: number; name: string; code: string } | null;
  is_active: boolean;
  created_at: string | null;
}

/** Asset reference nested into requests and records. */
export interface MaintenanceAssetRef {
  id: number;
  asset_code: string;
  name: string;
  status: string;
}

/** Request reference nested into a work record. */
export interface MaintenanceRecordRequestRef {
  id: number;
  title: string;
  status: MaintenanceStatus;
}

/** Item reference nested into a maintenance part. */
export interface MaintenanceItemRef {
  id: number;
  sku: string;
  name: string;
  unit: string;
}

/** Mirrors the backend MaintenancePartResource. */
export interface MaintenancePart {
  id: number;
  quantity: number;
  item: MaintenanceItemRef | null;
  created_at: string | null;
}

/** Mirrors the backend MaintenanceRecordResource. */
export interface MaintenanceRecord {
  id: number;
  description: string;
  result: string | null;
  /** Cost as a decimal string, matching the backend decimal column. */
  cost: string | null;
  started_at: string | null;
  completed_at: string | null;
  request: MaintenanceRecordRequestRef | null;
  asset: MaintenanceAssetRef | null;
  technician: MaintenanceUser | null;
  parts?: MaintenancePart[] | null;
  parts_count?: number | null;
  created_at: string | null;
  updated_at: string | null;
}

/** Mirrors the backend MaintenanceRequestResource. */
export interface MaintenanceRequest {
  id: number;
  title: string;
  description: string | null;
  priority: MaintenancePriority;
  status: MaintenanceStatus;
  requested_at: string | null;
  approved_at: string | null;
  completed_at: string | null;
  asset: MaintenanceAssetRef | null;
  requester: MaintenanceUser | null;
  assignee: MaintenanceUser | null;
  /** Work records — present on detail responses only. */
  records?: MaintenanceRecord[] | null;
  created_at: string | null;
  updated_at: string | null;
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

export interface MaintenanceRequestListFilters {
  search?: string;
  status?: MaintenanceStatus;
  priority?: MaintenancePriority;
  asset_id?: number | string;
  requester_id?: number | string;
  assigned_to?: number | string;
  location_id?: number | string;
  requested_from?: string;
  requested_to?: string;
  sort?: 'priority' | 'status' | 'requested_at' | 'created_at' | 'updated_at';
  direction?: 'asc' | 'desc';
  page?: number;
  per_page?: number;
}

export interface MaintenanceRecordListFilters {
  maintenance_request_id?: number | string;
  asset_id?: number | string;
  technician_id?: number | string;
  sort?: 'created_at' | 'started_at' | 'completed_at' | 'cost';
  direction?: 'asc' | 'desc';
  page?: number;
  per_page?: number;
}

/** Create a maintenance request. Ownership/status/timestamps are server-set. */
export interface MaintenanceRequestPayload {
  asset_id: number;
  title: string;
  description: string;
  priority?: MaintenancePriority | null;
}

/** Workflow, assignment, and editable fields. */
export interface MaintenanceRequestUpdatePayload {
  title?: string;
  description?: string;
  priority?: MaintenancePriority | null;
  status?: MaintenanceStatus;
  assigned_to?: number | null;
}

/** Open a work order (Start Work). The request auto-transitions server-side. */
export interface MaintenanceRecordPayload {
  maintenance_request_id: number;
  description: string;
  started_at?: string | null;
  completed_at?: string | null;
  result?: string | null;
  cost?: number | null;
  technician_id?: number | null;
}

/** Work fields only — the asset and request can never change on a record. */
export interface MaintenanceRecordUpdatePayload {
  description?: string;
  started_at?: string | null;
  completed_at?: string | null;
  result?: string | null;
  cost?: number | null;
  technician_id?: number | null;
}

/** Trace-only part on a work record. Never creates a stock movement. */
export interface MaintenancePartPayload {
  item_id: number;
  quantity: number;
}

/** User-friendly error from a maintenance API call. */
export interface MaintenanceErrorInfo {
  status: number | null;
  message: string;
  fieldErrors: Record<string, string>;
  /** True when a request/record was rejected due to permissions (do not leak fields). */
  accessDenied: boolean;
  /** True when the backend rejected a status/workflow action it cannot apply. */
  workflowRejected: boolean;
}

/**
 * Translate an HTTP failure from the maintenance APIs into safe, user-facing
 * text and (for 422 validation) per-field messages. Backend internals are
 * never surfaced verbatim. Business rejections that the user can act on
 * (invalid workflow transitions, ineligible assets) carry their message
 * through with flags the workspace UI uses to react.
 */
export function mapMaintenanceError(error: unknown): MaintenanceErrorInfo {
  const info: MaintenanceErrorInfo = {
    status: null,
    message: 'Something went wrong. Please try again.',
    fieldErrors: {},
    accessDenied: false,
    workflowRejected: false,
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
      info.workflowRejected = message.toLowerCase().includes('transition')
        || message.toLowerCase().includes('maintenance work can only be started');
    }
    return info;
  }

  if (error.status === 403) {
    const message = body?.message?.trim();
    if (message?.toLowerCase().includes('access')) {
      info.message = 'You do not have access to this maintenance record.';
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
 * MaintenanceService is the single frontend client for the backend
 * maintenance domain: maintenance requests, work records, and the
 * trace-only parts journal. Request status is never derived client-side —
 * the backend is authoritative and work orders open through the record
 * endpoints, never through a direct status push.
 */
@Injectable({ providedIn: 'root' })
export class MaintenanceService {
  private readonly api = inject(ApiService);

  // ─── Requests ────────────────────────────────────────────────────────────

  listRequests(filters: MaintenanceRequestListFilters = {}): Observable<ApiResponse<Paginated<MaintenanceRequest>>> {
    return this.api.get<Paginated<MaintenanceRequest>>('/maintenance-requests', this.cleanParams(filters));
  }

  getRequest(id: number): Observable<ApiResponse<MaintenanceRequest>> {
    return this.api.get<MaintenanceRequest>(`/maintenance-requests/${id}`);
  }

  createRequest(payload: MaintenanceRequestPayload): Observable<ApiResponse<MaintenanceRequest>> {
    return this.api.post<MaintenanceRequest>('/maintenance-requests', payload);
  }

  updateRequest(id: number, payload: MaintenanceRequestUpdatePayload): Observable<ApiResponse<MaintenanceRequest>> {
    return this.api.put<MaintenanceRequest>(`/maintenance-requests/${id}`, payload);
  }

  // ─── Records (work orders) ───────────────────────────────────────────────

  listRecords(filters: MaintenanceRecordListFilters = {}): Observable<ApiResponse<Paginated<MaintenanceRecord>>> {
    return this.api.get<Paginated<MaintenanceRecord>>('/maintenance-records', this.cleanParams(filters));
  }

  getRecord(id: number): Observable<ApiResponse<MaintenanceRecord>> {
    return this.api.get<MaintenanceRecord>(`/maintenance-records/${id}`);
  }

  /** Open a work order against a request (auto-transitions APPROVED → IN_PROGRESS server-side). */
  createRecord(payload: MaintenanceRecordPayload): Observable<ApiResponse<MaintenanceRecord>> {
    return this.api.post<MaintenanceRecord>('/maintenance-records', payload);
  }

  /** Update work fields only. The asset/request of a record are immutable. */
  updateRecord(id: number, payload: MaintenanceRecordUpdatePayload): Observable<ApiResponse<MaintenanceRecord>> {
    return this.api.put<MaintenanceRecord>(`/maintenance-records/${id}`, payload);
  }

  // ─── Parts (trace-only journal) ──────────────────────────────────────────

  listParts(recordId: number, perPage = 100): Observable<ApiResponse<Paginated<MaintenancePart>>> {
    return this.api.get<Paginated<MaintenancePart>>(`/maintenance-records/${recordId}/parts`, { per_page: perPage });
  }

  createPart(recordId: number, payload: MaintenancePartPayload): Observable<ApiResponse<MaintenancePart>> {
    return this.api.post<MaintenancePart>(`/maintenance-records/${recordId}/parts`, payload);
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