import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { HttpErrorResponse } from '@angular/common/http';

import {
  InventoryService,
  ItemPayload,
  StockMovementPayload,
  mapInventoryError,
} from './inventory.service';

function envelope<T>(data: T, message = 'ok') {
  return { success: true, message, data };
}

function paginated<T>(items: T[], perPage = 15) {
  return {
    items,
    pagination: { current_page: 1, per_page: perPage, total: items.length, last_page: 1 },
  };
}

describe('InventoryService', () => {
  let service: InventoryService;
  let controller: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
      ],
    });
    service = TestBed.inject(InventoryService);
    controller = TestBed.inject(HttpTestingController);
  });

  afterEach(() => {
    controller.verify();
  });

  const category = {
    id: 1,
    name: 'Office Supplies',
    code: 'OFC',
    description: null,
    items_count: 2,
    created_at: null,
    updated_at: null,
  };

  const warehouse = {
    id: 2,
    name: 'Main Warehouse',
    code: 'MAIN',
    description: null,
    is_active: true,
    location: { id: 3, name: 'HQ Office', code: 'HQ' },
    created_at: null,
    updated_at: null,
  };

  const item = {
    id: 1,
    sku: 'ITEM-001',
    name: 'Ballpoint Pen',
    description: null,
    unit: 'unit',
    minimum_stock: null,
    maximum_stock: null,
    is_active: true,
    category: { id: 1, name: 'Office Supplies', code: 'OFC' },
    stock: { total: 12, warehouses: [{ warehouse: { id: 2, name: 'Main Warehouse', code: 'MAIN' }, quantity: 8 }] },
    created_at: null,
    updated_at: null,
  };

  const movement = {
    id: 5,
    type: 'STOCK_IN',
    quantity: 4,
    reference_type: null,
    reference_id: null,
    notes: 'Restock',
    item: { id: 1, sku: 'ITEM-001', name: 'Ballpoint Pen' },
    warehouse: { id: 2, name: 'Main Warehouse', code: 'MAIN' },
    performer: { id: 7, name: 'Nadira', email: 'nadira@nexora.test' },
    created_at: '2025-02-10T08:00:00Z',
  };

  it('lists items with pagination, sort and filter params, omitting empty ones', () => {
    service.listItems({
      search: 'Pen',
      item_category_id: 1,
      warehouse_id: '',
      is_active: true,
      sort: 'sku',
      direction: 'asc',
      page: 2,
      per_page: 15,
    }).subscribe(res => {
      expect(res.data.items).toEqual([item]);
    });

    const request = controller.expectOne(req => req.url === '/api/v1/items');
    expect(request.request.params.get('search')).toBe('Pen');
    expect(request.request.params.get('item_category_id')).toBe('1');
    expect(request.request.params.has('warehouse_id')).toBe(false);
    expect(request.request.params.get('is_active')).toBe('true');
    expect(request.request.params.get('sort')).toBe('sku');
    expect(request.request.params.get('direction')).toBe('asc');
    expect(request.request.params.get('page')).toBe('2');
    expect(request.request.params.get('per_page')).toBe('15');
    request.flush(envelope(paginated([item], 15)));
  });

  it('gets a single item with its relations', () => {
    service.getItem(1).subscribe(res => {
      expect(res.data.id).toBe(1);
      expect(res.data.stock.warehouses?.[0].quantity).toBe(8);
    });

    const request = controller.expectOne('/api/v1/items/1');
    expect(request.request.method).toBe('GET');
    request.flush(envelope(item));
  });

  it('creates an item', () => {
    const payload = { item_category_id: 1, sku: 'ITEM-002', name: 'Notebook', unit: 'unit', is_active: true };
    service.createItem(payload).subscribe(res => {
      expect(res.data.sku).toBe(payload.sku);
    });

    const request = controller.expectOne('/api/v1/items');
    expect(request.request.method).toBe('POST');
    expect(request.request.body).toEqual(payload);
    request.flush(envelope({ ...item, id: 2, sku: payload.sku }));
  });

  it('updates an item', () => {
    const update: ItemPayload = { item_category_id: 1, sku: 'ITEM-001', name: 'Ballpoint Pen 0.7mm', unit: 'unit' };
    service.updateItem(1, update).subscribe(res => {
      expect(res.data.name).toBe('Ballpoint Pen 0.7mm');
    });

    const request = controller.expectOne('/api/v1/items/1');
    expect(request.request.method).toBe('PUT');
    expect(request.request.body).toEqual(update);
    request.flush(envelope({ ...item, name: 'Ballpoint Pen 0.7mm' }));
  });

  it('deletes an item', () => {
    service.deleteItem(3).subscribe(res => {
      expect(res.success).toBe(true);
    });

    const request = controller.expectOne('/api/v1/items/3');
    expect(request.request.method).toBe('DELETE');
    request.flush(envelope(null));
  });

  it('lists item categories with sort and pagination', () => {
    service.listCategories({ sort: 'name', direction: 'asc', page: 1, per_page: 100 }).subscribe(res => {
      expect(res.data.items.length).toBe(1);
    });

    const request = controller.expectOne(req => req.url === '/api/v1/item-categories');
    expect(request.request.params.get('sort')).toBe('name');
    expect(request.request.params.get('per_page')).toBe('100');
    request.flush(envelope(paginated([category], 100)));
  });

  it('creates and updates a category', () => {
    const payload = { name: 'Stationery', code: 'STN', description: null };
    service.createCategory(payload).subscribe();
    const create = controller.expectOne('/api/v1/item-categories');
    expect(create.request.method).toBe('POST');
    expect(create.request.body).toEqual(payload);
    create.flush(envelope({ ...category, id: 4, name: 'Stationery', code: 'STN' }));

    service.updateCategory(4, payload).subscribe();
    const update = controller.expectOne('/api/v1/item-categories/4');
    expect(update.request.method).toBe('PUT');
    update.flush(envelope({ ...category, id: 4, name: 'Stationery', code: 'STN' }));
  });

  it('deletes a category', () => {
    service.deleteCategory(4).subscribe(res => expect(res.success).toBe(true));
    const request = controller.expectOne('/api/v1/item-categories/4');
    expect(request.request.method).toBe('DELETE');
    request.flush(envelope(null));
  });

  it('lists warehouses with filters', () => {
    service.listWarehouses({ search: 'Main', location_id: 3, is_active: true, per_page: 100 }).subscribe(res => {
      expect(res.data.items.length).toBe(1);
    });

    const request = controller.expectOne(req => req.url === '/api/v1/warehouses');
    expect(request.request.params.get('search')).toBe('Main');
    expect(request.request.params.get('location_id')).toBe('3');
    expect(request.request.params.get('is_active')).toBe('true');
    expect(request.request.params.get('per_page')).toBe('100');
    request.flush(envelope(paginated([warehouse], 100)));
  });

  it('creates, gets and updates a warehouse', () => {
    const payload = { name: 'Overflow', code: 'OVF', location_id: 3, is_active: true };
    service.createWarehouse(payload).subscribe();
    const create = controller.expectOne('/api/v1/warehouses');
    expect(create.request.method).toBe('POST');
    expect(create.request.body).toEqual(payload);
    create.flush(envelope({ ...warehouse, id: 6, name: 'Overflow', code: 'OVF' }));

    service.getWarehouse(6).subscribe(res => expect(res.data.code).toBe('OVF'));
    const get = controller.expectOne('/api/v1/warehouses/6');
    get.flush(envelope({ ...warehouse, id: 6, name: 'Overflow', code: 'OVF' }));

    service.updateWarehouse(6, { ...payload, description: 'Overflow storage' }).subscribe();
    const update = controller.expectOne('/api/v1/warehouses/6');
    expect(update.request.method).toBe('PUT');
    update.flush(envelope({ ...warehouse, id: 6, description: 'Overflow storage' }));
  });

  it('deletes a warehouse', () => {
    service.deleteWarehouse(6).subscribe(res => expect(res.success).toBe(true));
    const request = controller.expectOne('/api/v1/warehouses/6');
    expect(request.request.method).toBe('DELETE');
    request.flush(envelope(null));
  });

  it('lists stock movements with item, warehouse and type filters', () => {
    service.listStockMovements({ item_id: 1, warehouse_id: 2, type: 'STOCK_IN', per_page: 15 }).subscribe(res => {
      expect(res.data.items.length).toBe(1);
    });

    const request = controller.expectOne(req => req.url === '/api/v1/stock-movements');
    expect(request.request.params.get('item_id')).toBe('1');
    expect(request.request.params.get('warehouse_id')).toBe('2');
    expect(request.request.params.get('type')).toBe('STOCK_IN');
    expect(request.request.params.get('per_page')).toBe('15');
    request.flush(envelope(paginated([movement], 15)));
  });

  it('gets a single stock movement', () => {
    service.getStockMovement(5).subscribe(res => {
      expect(res.data.quantity).toBe(4);
      expect(res.data.performer?.name).toBe('Nadira');
    });

    const request = controller.expectOne('/api/v1/stock-movements/5');
    expect(request.request.method).toBe('GET');
    request.flush(envelope(movement));
  });

  it('creates a stock movement', () => {
    const payload: StockMovementPayload = { item_id: 1, warehouse_id: 2, type: 'STOCK_OUT', quantity: 3, notes: 'Issued to fieldwork.' };
    service.createStockMovement(payload).subscribe(res => {
      expect(res.data.type).toBe('STOCK_OUT');
    });

    const request = controller.expectOne('/api/v1/stock-movements');
    expect(request.request.method).toBe('POST');
    expect(request.request.body).toEqual(payload);
    request.flush(envelope({ ...movement, id: 6, type: 'STOCK_OUT', quantity: 3 }));
  });
});

describe('mapInventoryError', () => {
  function httpError(status: number, body?: unknown): HttpErrorResponse {
    return new HttpErrorResponse({ status, statusText: 'Error', error: body });
  }

  it('maps a 403 as unauthorized', () => {
    expect(mapInventoryError(httpError(403)).message).toBe('This action is unauthorized.');
  });

  it('maps a 404 as record missing', () => {
    const info = mapInventoryError(httpError(404));
    expect(info.status).toBe(404);
    expect(info.message).toContain('no longer exists');
  });

  it('surfaces the backend message for a 409 conflict', () => {
    const info = mapInventoryError(httpError(409, { message: 'This category still has items.' }));
    expect(info.message).toBe('This category still has items.');
  });

  it('falls back to a default message for a 409 without a body', () => {
    expect(mapInventoryError(httpError(409)).message).toContain('still in use');
  });

  it('extracts per-field messages from a 422 validation response', () => {
    const info = mapInventoryError(httpError(422, {
      message: 'The given data was invalid.',
      errors: { sku: ['The sku has already been taken.'], unit: ['The unit field is required.'] },
    }));
    expect(info.fieldErrors['sku']).toBe('The sku has already been taken.');
    expect(info.fieldErrors['unit']).toBe('The unit field is required.');
    expect(info.message).toBe('Please check the highlighted fields.');
    expect(info.insufficientStock).toBe(false);
  });

  it('flags insufficient stock on a message-only 422', () => {
    const info = mapInventoryError(httpError(422, { success: false, message: 'Insufficient stock for this movement.' }));
    expect(info.fieldErrors).toEqual({});
    expect(info.status).toBe(422);
    expect(info.message).toBe('Insufficient stock for this movement.');
    expect(info.insufficientStock).toBe(true);
  });

  it('surfaces non-insufficient business rejections from a message-only 422', () => {
    const info = mapInventoryError(httpError(422, { success: false, message: 'Warehouse is archived.' }));
    expect(info.insufficientStock).toBe(false);
    expect(info.message).toBe('Warehouse is archived.');
  });

  it('maps connection failures (status 0)', () => {
    expect(mapInventoryError(httpError(0)).message).toBe('Unable to connect to the server.');
  });

  it('maps rate limiting (429)', () => {
    expect(mapInventoryError(httpError(429)).message).toBe('Too many attempts. Please try again later.');
  });

  it('maps server errors (5xx) to a generic message', () => {
    expect(mapInventoryError(httpError(500)).message).toBe('Something went wrong. Please try again.');
  });

  it('falls back to the backend message for other statuses', () => {
    expect(mapInventoryError(httpError(400, { message: 'Bad request payload.' })).message).toBe('Bad request payload.');
  });

  it('handles unknown non-HTTP failures gracefully', () => {
    const info = mapInventoryError(new Error('boom'));
    expect(info.status).toBeNull();
    expect(info.message).toBe('Something went wrong. Please try again.');
  });
});