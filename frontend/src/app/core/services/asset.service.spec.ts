import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { HttpErrorResponse } from '@angular/common/http';

import {
  AssetService,
  AssetPayload,
  AssignmentPayload,
  mapAssetError,
} from './asset.service';

function envelope<T>(data: T, message = 'ok') {
  return { success: true, message, data };
}

describe('AssetService', () => {
  let service: AssetService;
  let controller: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
      ],
    });
    service = TestBed.inject(AssetService);
    controller = TestBed.inject(HttpTestingController);
  });

  afterEach(() => {
    controller.verify();
  });

  const asset = {
    id: 1,
    asset_code: 'NX-LPT-001',
    name: 'Lenovo ThinkPad X1',
    description: null,
    serial_number: 'PFQSD123456',
    status: 'ACTIVE',
    condition: 'GOOD',
    purchase_date: '2025-01-10',
    purchase_price: 1250.5,
    warranty_expiry: null,
    category: { id: 1, name: 'Laptops', code: 'LPT' },
    location: { id: 2, name: 'HQ Office', code: 'HQ' },
    current_user: null,
    created_at: '2025-02-01T10:00:00Z',
    updated_at: '2025-02-01T10:00:00Z',
  };

  const payload: AssetPayload = {
    asset_code: 'NX-LPT-002',
    name: 'Dell Latitude',
    asset_category_id: 1,
    status: 'ACTIVE',
    condition: 'GOOD',
    serial_number: 'SN002',
    location_id: 2,
  };

  it('lists assets with sort, direction and pagination params', () => {
    service.listAssets({ page: 2, per_page: 15, sort: 'asset_code', direction: 'asc' }).subscribe(res => {
      expect(res.success).toBe(true);
      expect(res.data.items).toEqual([asset]);
      expect(res.data.pagination.total).toBe(1);
    });

    const request = controller.expectOne(req => req.url === '/api/v1/assets');
    expect(request.request.params.get('page')).toBe('2');
    expect(request.request.params.get('per_page')).toBe('15');
    expect(request.request.params.get('sort')).toBe('asset_code');
    expect(request.request.params.get('direction')).toBe('asc');
    request.flush(envelope({
      items: [asset],
      pagination: { current_page: 2, per_page: 15, total: 1, last_page: 1 },
    }));
  });

  it('lists assets with filter params and omits empty ones', () => {
    service.listAssets({ status: 'ACTIVE', asset_category_id: 3, location_id: '', search: '' }).subscribe();

    const request = controller.expectOne(req => req.url === '/api/v1/assets');
    expect(request.request.params.get('status')).toBe('ACTIVE');
    expect(request.request.params.get('asset_category_id')).toBe('3');
    expect(request.request.params.has('location_id')).toBe(false);
    expect(request.request.params.has('search')).toBe(false);
    request.flush(envelope({ items: [], pagination: { current_page: 1, per_page: 15, total: 0, last_page: 1 } }));
  });

  it('gets a single asset with its relations', () => {
    service.getAsset(1).subscribe(res => {
      expect(res.data.id).toBe(1);
      expect(res.data.category?.code).toBe('LPT');
    });

    const request = controller.expectOne('/api/v1/assets/1');
    expect(request.request.method).toBe('GET');
    request.flush(envelope(asset));
  });

  it('creates an asset', () => {
    service.createAsset(payload).subscribe(res => {
      expect(res.success).toBe(true);
      expect(res.data.asset_code).toBe(payload.asset_code);
    });

    const request = controller.expectOne('/api/v1/assets');
    expect(request.request.method).toBe('POST');
    expect(request.request.body).toEqual(payload);
    request.flush(envelope({ ...asset, id: 2, asset_code: payload.asset_code }));
  });

  it('updates an asset', () => {
    const update: AssetPayload = { ...payload, name: 'Dell Latitude 5420' };
    service.updateAsset(2, update).subscribe(res => {
      expect(res.data.name).toBe('Dell Latitude 5420');
    });

    const request = controller.expectOne('/api/v1/assets/2');
    expect(request.request.method).toBe('PUT');
    expect(request.request.body).toEqual(update);
    request.flush(envelope({ ...asset, id: 2, name: 'Dell Latitude 5420' }));
  });

  it('deletes an asset', () => {
    service.deleteAsset(3).subscribe(res => {
      expect(res.success).toBe(true);
    });

    const request = controller.expectOne('/api/v1/assets/3');
    expect(request.request.method).toBe('DELETE');
    request.flush(envelope(null));
  });

  it('lists asset categories', () => {
    service.listCategories({ per_page: 100 }).subscribe(res => {
      expect(res.data.items.length).toBe(1);
    });

    const request = controller.expectOne(req => req.url === '/api/v1/asset-categories');
    expect(request.request.params.get('per_page')).toBe('100');
    request.flush(envelope({
      items: [{ id: 1, name: 'Laptops', code: 'LPT', description: null, assets_count: 0, created_at: null, updated_at: null }],
      pagination: { current_page: 1, per_page: 100, total: 1, last_page: 1 },
    }));
  });

  it('lists assignments filtered by asset and status', () => {
    service.listAssignments({ asset_id: 1, status: 'ACTIVE', per_page: 1 }).subscribe(res => {
      expect(res.data.items.length).toBe(0);
    });

    const request = controller.expectOne(req => req.url === '/api/v1/asset-assignments');
    expect(request.request.params.get('asset_id')).toBe('1');
    expect(request.request.params.get('status')).toBe('ACTIVE');
    expect(request.request.params.get('per_page')).toBe('1');
    request.flush(envelope({ items: [], pagination: { current_page: 1, per_page: 1, total: 0, last_page: 1 } }));
  });

  it('creates an assignment', () => {
    const assignment: AssignmentPayload = { asset_id: 1, user_id: 7, location_id: 2, notes: 'Handover complete.' };
    service.createAssignment(assignment).subscribe(res => {
      expect(res.success).toBe(true);
    });

    const request = controller.expectOne('/api/v1/asset-assignments');
    expect(request.request.method).toBe('POST');
    expect(request.request.body).toEqual(assignment);
    request.flush(envelope({
      id: 9,
      asset: { id: 1, asset_code: 'NX-LPT-001', name: 'Lenovo' },
      user: { id: 7, name: 'Nadira', email: 'nadira@nexora.test' },
      requested_by: null,
      location: null,
      status: 'ACTIVE',
      assigned_at: '2025-02-02T08:00:00Z',
      returned_at: null,
      notes: 'Handover complete.',
      created_at: null,
      updated_at: null,
    }));
  });

  it('returns an assignment', () => {
    service.returnAssignment(9).subscribe(res => {
      expect(res.success).toBe(true);
    });

    const request = controller.expectOne('/api/v1/asset-assignments/9/return');
    expect(request.request.method).toBe('POST');
    request.flush(envelope({
      id: 9,
      asset: null,
      user: null,
      requested_by: null,
      location: null,
      status: 'RETURNED',
      assigned_at: null,
      returned_at: '2025-02-05T08:00:00Z',
      notes: null,
      created_at: null,
      updated_at: null,
    }));
  });

  it('fetches QR metadata for an asset', () => {
    service.getQrMetadata(1).subscribe(res => {
      expect(res.data).toEqual({ asset_id: 1, identifier: 'NX-LPT-001', payload: 'NEXORA:ASSET:NX-LPT-001' });
    });

    const request = controller.expectOne('/api/v1/assets/1/qr');
    expect(request.request.method).toBe('GET');
    request.flush(envelope({ asset_id: 1, identifier: 'NX-LPT-001', payload: 'NEXORA:ASSET:NX-LPT-001' }));
  });

  it('looks up an asset by QR identifier, URL-encoding the identifier', () => {
    service.lookupQr('NEXORA:ASSET:NX-LPT-001').subscribe(res => {
      expect(res.data.id).toBe(1);
    });

    const request = controller.expectOne(req => req.urlWithParams.includes('/api/v1/assets/qr/NEXORA%3AASSET%3ANX-LPT-001'));
    expect(request.request.method).toBe('GET');
    request.flush(envelope(asset));
  });

  it('lists users for the assignment picker', () => {
    service.listUsers({ per_page: 100 }).subscribe(res => {
      expect(res.data.items.length).toBe(1);
    });

    const request = controller.expectOne(req => req.url === '/api/v1/users');
    expect(request.request.params.get('per_page')).toBe('100');
    request.flush(envelope({
      items: [{ id: 7, name: 'Nadira', email: 'nadira@nexora.test', is_active: true, role: null, department: null, created_at: null, updated_at: null }],
      pagination: { current_page: 1, per_page: 100, total: 1, last_page: 1 },
    }));
  });

  it('lists locations for filters and forms', () => {
    service.listLocations({ per_page: 100 }).subscribe(res => {
      expect(res.data.items.length).toBe(1);
    });

    const request = controller.expectOne(req => req.url === '/api/v1/locations');
    request.flush(envelope({
      items: [{ id: 2, name: 'HQ Office', code: 'HQ', description: null, address: null, is_active: true, created_at: null, updated_at: null }],
      pagination: { current_page: 1, per_page: 100, total: 1, last_page: 1 },
    }));
  });
});

describe('mapAssetError', () => {
  function httpError(status: number, body?: unknown): HttpErrorResponse {
    return new HttpErrorResponse({ status, statusText: 'Error', error: body });
  }

  it('maps a 409 to an in-use warning', () => {
    const info = mapAssetError(httpError(409));
    expect(info.status).toBe(409);
    expect(info.message).toContain('still in use');
  });

  it('maps a 403 as unauthorized', () => {
    expect(mapAssetError(httpError(403)).message).toBe('This action is unauthorized.');
  });

  it('maps a 404 as record missing', () => {
    expect(mapAssetError(httpError(404)).message).toContain('no longer exists');
  });

  it('extracts per-field messages from a 422 validation response', () => {
    const info = mapAssetError(httpError(422, {
      message: 'The given data was invalid.',
      errors: { asset_code: ['The asset code has already been taken.'], name: ['The name field is required.'] },
    }));
    expect(info.fieldErrors['asset_code']).toBe('The asset code has already been taken.');
    expect(info.fieldErrors['name']).toBe('The name field is required.');
    expect(info.message).toBe('Please check the highlighted fields.');
  });

  it('handles unknown non-HTTP failures gracefully', () => {
    const info = mapAssetError(new Error('boom'));
    expect(info.status).toBeNull();
    expect(info.message).toBe('Something went wrong. Please try again.');
  });

  it('maps connection failures (status 0)', () => {
    expect(mapAssetError(httpError(0)).message).toBe('Unable to connect to the server.');
  });

  it('maps server errors (5xx) to a generic message', () => {
    expect(mapAssetError(httpError(500)).message).toBe('Something went wrong. Please try again.');
  });

  it('falls back to the backend message for other statuses', () => {
    expect(mapAssetError(httpError(400, { message: 'Bad request payload.' })).message).toBe('Bad request payload.');
  });
});