import { Injectable, inject } from '@angular/core';
import { HttpErrorResponse } from '@angular/common/http';
import { Observable } from 'rxjs';

import { ApiService, ApiResponse } from './api.service';

/**
 * Domain types for the Inventory workspace, mirroring the backend resources
 * exactly (ItemResource, ItemCategoryResource, WarehouseResource,
 * StockMovementResource).
 */

export type MovementType = 'STOCK_IN' | 'STOCK_OUT';

export interface ItemCategoryRef {
  id: number;
  name: string;
  code: string;
}

export interface UserRef {
  id: number;
  name: string;
  email: string;
}

export interface ItemCategory {
  id: number;
  name: string;
  code: string;
  description: string | null;
  items_count: number | null;
  created_at: string | null;
  updated_at: string | null;
}

export interface WarehouseStock {
  warehouse: ItemCategoryRef;
  quantity: number;
}

export interface StockSummary {
  total: number;
  warehouses?: WarehouseStock[];
}

export interface InventoryItem {
  id: number;
  sku: string;
  name: string;
  description: string | null;
  unit: string;
  minimum_stock: number | null;
  maximum_stock: number | null;
  is_active: boolean;
  category: ItemCategoryRef | null;
  stock: StockSummary;
  created_at: string | null;
  updated_at: string | null;
}

export interface InventoryWarehouse {
  id: number;
  name: string;
  code: string;
  description: string | null;
  is_active: boolean;
  location: ItemCategoryRef | null;
  created_at: string | null;
  updated_at: string | null;
}

export interface StockMovement {
  id: number;
  type: MovementType;
  quantity: number;
  reference_type: string | null;
  reference_id: number | null;
  notes: string | null;
  item: { id: number; sku: string; name: string } | null;
  warehouse: ItemCategoryRef | null;
  performer: UserRef | null;
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

export interface ItemListFilters {
  search?: string;
  item_category_id?: number | string;
  warehouse_id?: number | string;
  is_active?: boolean;
  sort?: string;
  direction?: 'asc' | 'desc';
  page?: number;
  per_page?: number;
}

export interface CategoryListFilters {
  search?: string;
  sort?: string;
  direction?: 'asc' | 'desc';
  page?: number;
  per_page?: number;
}

export interface WarehouseListFilters {
  search?: string;
  location_id?: number | string;
  is_active?: boolean;
  sort?: string;
  direction?: 'asc' | 'desc';
  page?: number;
  per_page?: number;
}

export interface MovementListFilters {
  item_id?: number | string;
  warehouse_id?: number | string;
  type?: MovementType;
  sort?: string;
  direction?: 'asc' | 'desc';
  page?: number;
  per_page?: number;
}

export interface ItemPayload {
  item_category_id: number;
  sku: string;
  name: string;
  description?: string | null;
  unit: string;
  minimum_stock?: number | null;
  maximum_stock?: number | null;
  is_active?: boolean;
}

export interface CategoryPayload {
  name: string;
  code: string;
  description?: string | null;
}

export interface WarehousePayload {
  name: string;
  code: string;
  location_id?: number | null;
  description?: string | null;
  is_active?: boolean;
}

export interface StockMovementPayload {
  item_id: number;
  warehouse_id: number;
  type: MovementType;
  quantity: number;
  notes?: string | null;
}

/** User-friendly error from an inventory API call. */
export interface InventoryErrorInfo {
  status: number | null;
  message: string;
  fieldErrors: Record<string, string>;
  /** True when backend rejected a STOCK_OUT that exceeds available balance. */
  insufficientStock: boolean;
}

/**
 * Translate an HTTP failure from the inventory APIs into safe, user-facing
 * text and (for 422 validation) per-field messages. Backend internals are
 * never surfaced verbatim. A 422 business rejection (e.g. insufficient stock)
 * carries its message through so the user can act on it.
 */
export function mapInventoryError(error: unknown): InventoryErrorInfo {
  const info: InventoryErrorInfo = {
    status: null,
    message: 'Something went wrong. Please try again.',
    fieldErrors: {},
    insufficientStock: false,
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
      info.insufficientStock = message.toLowerCase().includes('insufficient stock');
    }
    return info;
  }

  const messages: Record<number, string> = {
    403: 'This action is unauthorized.',
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
 * InventoryService is the single frontend client for the backend inventory
 * domain: item categories, items, warehouses, and the immutable stock
 * movement journal. Stock balance is never computed here — the backend is
 * authoritative and returns it with every item.
 */
@Injectable({ providedIn: 'root' })
export class InventoryService {
  private readonly api = inject(ApiService);

  // ─── Item categories ──────────────────────────────────────────────────────

  listCategories(filters: CategoryListFilters = {}): Observable<ApiResponse<Paginated<ItemCategory>>> {
    return this.api.get<Paginated<ItemCategory>>('/item-categories', this.cleanParams(filters));
  }

  getCategory(id: number): Observable<ApiResponse<ItemCategory>> {
    return this.api.get<ItemCategory>(`/item-categories/${id}`);
  }

  createCategory(payload: CategoryPayload): Observable<ApiResponse<ItemCategory>> {
    return this.api.post<ItemCategory>('/item-categories', payload);
  }

  updateCategory(id: number, payload: CategoryPayload): Observable<ApiResponse<ItemCategory>> {
    return this.api.put<ItemCategory>(`/item-categories/${id}`, payload);
  }

  deleteCategory(id: number): Observable<ApiResponse<null>> {
    return this.api.delete<null>(`/item-categories/${id}`);
  }

  // ─── Items ────────────────────────────────────────────────────────────────

  listItems(filters: ItemListFilters = {}): Observable<ApiResponse<Paginated<InventoryItem>>> {
    return this.api.get<Paginated<InventoryItem>>('/items', this.cleanParams(filters));
  }

  getItem(id: number): Observable<ApiResponse<InventoryItem>> {
    return this.api.get<InventoryItem>(`/items/${id}`);
  }

  createItem(payload: ItemPayload): Observable<ApiResponse<InventoryItem>> {
    return this.api.post<InventoryItem>('/items', payload);
  }

  updateItem(id: number, payload: ItemPayload): Observable<ApiResponse<InventoryItem>> {
    return this.api.put<InventoryItem>(`/items/${id}`, payload);
  }

  deleteItem(id: number): Observable<ApiResponse<null>> {
    return this.api.delete<null>(`/items/${id}`);
  }

  // ─── Warehouses ───────────────────────────────────────────────────────────

  listWarehouses(filters: WarehouseListFilters = {}): Observable<ApiResponse<Paginated<InventoryWarehouse>>> {
    return this.api.get<Paginated<InventoryWarehouse>>('/warehouses', this.cleanParams(filters));
  }

  getWarehouse(id: number): Observable<ApiResponse<InventoryWarehouse>> {
    return this.api.get<InventoryWarehouse>(`/warehouses/${id}`);
  }

  createWarehouse(payload: WarehousePayload): Observable<ApiResponse<InventoryWarehouse>> {
    return this.api.post<InventoryWarehouse>('/warehouses', payload);
  }

  updateWarehouse(id: number, payload: WarehousePayload): Observable<ApiResponse<InventoryWarehouse>> {
    return this.api.put<InventoryWarehouse>(`/warehouses/${id}`, payload);
  }

  deleteWarehouse(id: number): Observable<ApiResponse<null>> {
    return this.api.delete<null>(`/warehouses/${id}`);
  }

  // ─── Stock movements ──────────────────────────────────────────────────────

  listStockMovements(filters: MovementListFilters = {}): Observable<ApiResponse<Paginated<StockMovement>>> {
    return this.api.get<Paginated<StockMovement>>('/stock-movements', this.cleanParams(filters));
  }

  getStockMovement(id: number): Observable<ApiResponse<StockMovement>> {
    return this.api.get<StockMovement>(`/stock-movements/${id}`);
  }

  createStockMovement(payload: StockMovementPayload): Observable<ApiResponse<StockMovement>> {
    return this.api.post<StockMovement>('/stock-movements', payload);
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