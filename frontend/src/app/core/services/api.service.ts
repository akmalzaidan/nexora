import { Injectable, inject, signal } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable, lastValueFrom } from 'rxjs';

/**
 * ApiService is the centralized HTTP client for the NEXORA frontend.
 *
 * All API calls go through this service to ensure consistent
 * base URL (`/api/v1`) and error handling.
 *
 * Response envelope:
 *   Success: { success: true, message: string, data: T }
 *   Error:   { success: false, message: string, errors: object }
 */
@Injectable({ providedIn: 'root' })
export class ApiService {
  private readonly http = inject(HttpClient);
  private readonly baseUrl = '/api/v1';

  /**
   * Perform a GET request.
   */
  get<T>(endpoint: string, params?: Record<string, string | number | boolean>): Observable<ApiResponse<T>> {
    return this.http.get<ApiResponse<T>>(`${this.baseUrl}${endpoint}`, {
      params: params as any,
    });
  }

  /**
   * Perform a POST request.
   */
  post<T>(endpoint: string, body: unknown): Observable<ApiResponse<T>> {
    return this.http.post<ApiResponse<T>>(`${this.baseUrl}${endpoint}`, body);
  }

  /**
   * Perform a PUT request.
   */
  put<T>(endpoint: string, body: unknown): Observable<ApiResponse<T>> {
    return this.http.put<ApiResponse<T>>(`${this.baseUrl}${endpoint}`, body);
  }

  /**
   * Perform a DELETE request.
   */
  delete<T>(endpoint: string): Observable<ApiResponse<T>> {
    return this.http.delete<ApiResponse<T>>(`${this.baseUrl}${endpoint}`);
  }

  /**
   * Convenience: get a single asset by ID.
   */
  getAsset(id: number): Observable<ApiResponse<Asset>> {
    return this.get<Asset>(`/assets/${id}`);
  }

  /**
   * Convenience: QR lookup by identifier.
   */
  qrLookup(identifier: string): Observable<ApiResponse<Asset>> {
    return this.get<Asset>(`/assets/qr/${encodeURIComponent(identifier)}`);
  }

  /**
   * Convenience: QR metadata for an asset.
   */
  qrMetadata(assetId: number): Observable<ApiResponse<QrMetadata>> {
    return this.get<QrMetadata>(`/assets/${assetId}/qr`);
  }
}

/** Generic API response envelope. */
export interface ApiResponse<T> {
  success: boolean;
  message: string;
  data: T;
}

/** Asset data shape mapping to backend AssetResource. */
export interface Asset {
  id: number;
  asset_code: string;
  name: string;
  description: string | null;
  serial_number: string | null;
  status: string;
  condition: string;
  purchase_date: string | null;
  purchase_price: number | null;
  warranty_expiry: string | null;
  category: Category | null;
  location: Location | null;
  current_user: User | null;
  created_at: string;
  updated_at: string;
}

export interface Category {
  id: number;
  name: string;
  code: string;
}

export interface Location {
  id: number;
  name: string;
  code: string;
}

export interface User {
  id: number;
  name: string;
  email: string;
}

export interface QrMetadata {
  asset_id: number;
  identifier: string;
  payload: string;
}
