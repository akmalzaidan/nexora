import { Injectable, inject } from '@angular/core';
import { HttpErrorResponse } from '@angular/common/http';
import { Observable } from 'rxjs';

import { ApiService, ApiResponse } from './api.service';

/**
 * Domain types for the Asset Management workspace, mirroring the backend
 * resources exactly (AssetResource, AssetCategoryResource,
 * AssetAssignmentResource, LocationResource, UserManagementResource).
 */

export type AssetStatus = 'DRAFT' | 'ACTIVE' | 'INACTIVE' | 'MAINTENANCE' | 'RETIRED' | 'LOST' | 'DISPOSED';
export type AssetCondition = 'GOOD' | 'FAIR' | 'POOR' | 'DAMAGED' | 'FAILED';
export type AssignmentStatus = 'ACTIVE' | 'RETURNED';

export interface CategoryRef {
  id: number;
  name: string;
  code: string;
}

export interface UserRef {
  id: number;
  name: string;
  email: string;
}

export interface Asset {
  id: number;
  asset_code: string;
  name: string;
  description: string | null;
  serial_number: string | null;
  status: AssetStatus;
  condition: AssetCondition;
  purchase_date: string | null;
  purchase_price: number | null;
  warranty_expiry: string | null;
  category: CategoryRef | null;
  location: CategoryRef | null;
  current_user: UserRef | null;
  created_at: string | null;
  updated_at: string | null;
}

export interface AssetCategoryItem {
  id: number;
  name: string;
  code: string;
  description: string | null;
  assets_count: number | null;
  created_at: string | null;
  updated_at: string | null;
}

export interface AssetAssignmentItem {
  id: number;
  asset: { id: number; asset_code: string; name: string } | null;
  user: UserRef | null;
  requested_by: { id: number; name: string } | null;
  location: CategoryRef | null;
  status: AssignmentStatus;
  assigned_at: string | null;
  returned_at: string | null;
  notes: string | null;
  created_at: string | null;
  updated_at: string | null;
}

export interface QrAssetMetadata {
  asset_id: number;
  identifier: string;
  payload: string;
}

export interface ManagedUser {
  id: number;
  name: string;
  email: string;
  is_active: boolean;
  role: { id: number; name: string; slug: string } | null;
  department: { id: number; name: string; code: string } | null;
  created_at: string | null;
  updated_at: string | null;
}

export interface LocationRecord {
  id: number;
  name: string;
  code: string;
  description: string | null;
  address: string | null;
  is_active: boolean;
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

export interface AssetListFilters {
  search?: string;
  status?: string;
  asset_category_id?: number | string;
  location_id?: number | string;
  sort?: 'name' | 'asset_code' | 'created_at';
  direction?: 'asc' | 'desc';
  page?: number;
  per_page?: number;
}

export interface AssetCategoryListFilters {
  search?: string;
  sort?: string;
  direction?: 'asc' | 'desc';
  per_page?: number;
}

export interface AssignmentListFilters {
  search?: string;
  asset_id?: number | string;
  user_id?: number | string;
  location_id?: number | string;
  status?: string;
  per_page?: number;
}

export interface UserListFilters {
  search?: string;
  is_active?: boolean;
  per_page?: number;
}

export interface LocationListFilters {
  search?: string;
  per_page?: number;
}

export interface AssetPayload {
  asset_category_id: number | null;
  asset_code: string;
  name: string;
  description?: string | null;
  serial_number?: string | null;
  status?: AssetStatus;
  condition?: AssetCondition;
  purchase_date?: string | null;
  purchase_price?: number | null;
  warranty_expiry?: string | null;
  location_id?: number | null;
  current_user_id?: number | null;
}

export interface AssignmentPayload {
  asset_id: number;
  user_id: number;
  location_id?: number | null;
  notes?: string | null;
}

/** User-friendly error from an asset/assignment/category API call. */
export interface AssetErrorInfo {
  status: number | null;
  message: string;
  fieldErrors: Record<string, string>;
}

/**
 * Translate an HTTP failure from the asset APIs into safe, user-facing text
 * and (for 422 validation) per-field messages. Backend internals are never
 * surfaced verbatim.
 */
export function mapAssetError(error: unknown): AssetErrorInfo {
  const info: AssetErrorInfo = { status: null, message: 'Something went wrong. Please try again.', fieldErrors: {} };

  if (!(error instanceof HttpErrorResponse)) {
    return info;
  }

  info.status = error.status;
  const body = error.error as { message?: string; errors?: Record<string, string[]> } | null;
  const messages: Record<number, string> = {
    403: 'This action is unauthorized.',
    404: 'The requested record no longer exists.',
    409: body?.message ?? 'This record is still in use and cannot be changed.',
    429: 'Too many attempts. Please try again later.',
  };

  if (error.status === 422 && body?.errors) {
    for (const [field, fieldMessages] of Object.entries(body.errors)) {
      if (fieldMessages?.length) {
        info.fieldErrors[field] = fieldMessages[0];
      }
    }
    info.message = 'Please check the highlighted fields.';
    return info;
  }

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
 * AssetService is the single frontend client for the backend asset domain:
 * assets, asset categories, QR identity, asset assignments, and the users /
 * locations lookups used to build forms and filters.
 */
@Injectable({ providedIn: 'root' })
export class AssetService {
  private readonly api = inject(ApiService);

  listAssets(filters: AssetListFilters = {}): Observable<ApiResponse<Paginated<Asset>>> {
    return this.api.get<Paginated<Asset>>('/assets', this.cleanParams(filters));
  }

  getAsset(id: number): Observable<ApiResponse<Asset>> {
    return this.api.get<Asset>(`/assets/${id}`);
  }

  createAsset(payload: AssetPayload): Observable<ApiResponse<Asset>> {
    return this.api.post<Asset>('/assets', payload);
  }

  updateAsset(id: number, payload: AssetPayload): Observable<ApiResponse<Asset>> {
    return this.api.put<Asset>(`/assets/${id}`, payload);
  }

  deleteAsset(id: number): Observable<ApiResponse<null>> {
    return this.api.delete<null>(`/assets/${id}`);
  }

  listCategories(filters: AssetCategoryListFilters = {}): Observable<ApiResponse<Paginated<AssetCategoryItem>>> {
    return this.api.get<Paginated<AssetCategoryItem>>('/asset-categories', this.cleanParams(filters));
  }

  listAssignments(filters: AssignmentListFilters = {}): Observable<ApiResponse<Paginated<AssetAssignmentItem>>> {
    return this.api.get<Paginated<AssetAssignmentItem>>('/asset-assignments', this.cleanParams(filters));
  }

  createAssignment(payload: AssignmentPayload): Observable<ApiResponse<AssetAssignmentItem>> {
    return this.api.post<AssetAssignmentItem>('/asset-assignments', payload);
  }

  returnAssignment(id: number): Observable<ApiResponse<AssetAssignmentItem>> {
    return this.api.post<AssetAssignmentItem>(`/asset-assignments/${id}/return`, {});
  }

  getQrMetadata(assetId: number): Observable<ApiResponse<QrAssetMetadata>> {
    return this.api.get<QrAssetMetadata>(`/assets/${assetId}/qr`);
  }

  lookupQr(identifier: string): Observable<ApiResponse<Asset>> {
    return this.api.get<Asset>(`/assets/qr/${encodeURIComponent(identifier)}`);
  }

  listUsers(filters: UserListFilters = {}): Observable<ApiResponse<Paginated<ManagedUser>>> {
    return this.api.get<Paginated<ManagedUser>>('/users', this.cleanParams(filters));
  }

  listLocations(filters: LocationListFilters = {}): Observable<ApiResponse<Paginated<LocationRecord>>> {
    return this.api.get<Paginated<LocationRecord>>('/locations', this.cleanParams(filters));
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