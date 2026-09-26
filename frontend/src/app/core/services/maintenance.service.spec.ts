import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { HttpErrorResponse } from '@angular/common/http';

import {
  MaintenanceService,
  MaintenanceRequest,
  MaintenanceRecord,
  MaintenancePart,
  MaintenanceRequestListFilters,
  mapMaintenanceError,
} from './maintenance.service';

function envelope<T>(data: T, message = 'ok') {
  return { success: true, message, data };
}

function paginated<T>(items: T[], total = items.length) {
  return {
    items,
    pagination: { current_page: 1, per_page: 15, total, last_page: 1 },
  };
}

const user = {
  id: 9,
  name: 'Nadira',
  email: 'nadira@nexora.test',
  role: { id: 3, name: 'Technician', slug: 'technician' },
  department: { id: 2, name: 'IT', code: 'IT' },
  is_active: true,
  created_at: null,
};

const asset = {
  id: 12,
  asset_code: 'AST-00342',
  name: 'Exhaust Fan',
  status: 'ACTIVE',
};

const request: MaintenanceRequest = {
  id: 7,
  title: 'Servo motor vibration',
  description: 'Excessive vibration on line 2.',
  priority: 'HIGH',
  status: 'REQUESTED',
  requested_at: '2025-03-01T08:00:00Z',
  approved_at: null,
  completed_at: null,
  asset,
  requester: user,
  assignee: null,
  created_at: '2025-03-01T08:00:00Z',
  updated_at: '2025-03-01T08:00:00Z',
};

const record: MaintenanceRecord = {
  id: 3,
  description: 'Balanced the shaft',
  result: 'Replaced bearing',
  cost: '125.50',
  started_at: '2025-03-02T09:00:00Z',
  completed_at: '2025-03-02T12:30:00Z',
  request: { id: 7, title: 'Servo motor vibration', status: 'IN_PROGRESS' },
  asset,
  technician: user,
  created_at: '2025-03-02T09:00:00Z',
  updated_at: '2025-03-02T12:30:00Z',
};

const part: MaintenancePart = {
  id: 1,
  quantity: 2,
  item: { id: 55, sku: 'BRG-6205', name: '6205 Ball Bearing', unit: 'pcs' },
  created_at: '2025-03-02T10:00:00Z',
};

describe('MaintenanceService', () => {
  let service: MaintenanceService;
  let controller: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
      ],
    });
    service = TestBed.inject(MaintenanceService);
    controller = TestBed.inject(HttpTestingController);
  });

  afterEach(() => {
    controller.verify();
  });

  it('lists maintenance requests with cleaned filter params', () => {
    service.listRequests({
      search: '  bearing  ',
      status: 'APPROVED',
      asset_id: 12,
      location_id: 3,
      requested_from: '2025-03-01',
      requested_to: '2025-03-31',
      sort: 'requested_at',
      direction: 'desc',
      page: 2,
      per_page: 15,
    }).subscribe(res => {
      expect(res.data.items.length).toBe(1);
      expect(res.data.pagination.total).toBe(1);
    });

    const req = controller.expectOne(r => r.url === '/api/v1/maintenance-requests');
    expect(req.request.method).toBe('GET');
    expect(req.request.params.get('status')).toBe('APPROVED');
    expect(req.request.params.get('asset_id')).toBe('12');
    expect(req.request.params.get('location_id')).toBe('3');
    expect(req.request.params.get('requested_from')).toBe('2025-03-01');
    expect(req.request.params.get('requested_to')).toBe('2025-03-31');
    expect(req.request.params.get('page')).toBe('2');
    expect(req.request.params.get('search')).toBe('  bearing  ');

    req.flush(envelope(paginated([request])));
  });

  it('omits empty filter params', () => {
    const filters: MaintenanceRequestListFilters = {
      status: '' as MaintenanceRequestListFilters['status'],
      priority: 'MEDIUM',
      asset_id: '' as MaintenanceRequestListFilters['asset_id'],
    };
    service.listRequests(filters).subscribe();

    const req = controller.expectOne(r => r.url === '/api/v1/maintenance-requests');
    expect(req.request.params.keys().sort()).toEqual(['priority']);
    req.flush(envelope(paginated([])));
  });

  it('fetches a single request', () => {
    service.getRequest(7).subscribe(res => {
      expect(res.data.id).toBe(7);
    });

    const req = controller.expectOne('/api/v1/maintenance-requests/7');
    expect(req.request.method).toBe('GET');
    req.flush(envelope(request));
  });

  it('creates a maintenance request (ownership/status are server-set)', () => {
    service.createRequest({
      asset_id: 12,
      title: 'Servo motor vibration',
      description: 'Excessive vibration on line 2.',
      priority: 'HIGH',
    }).subscribe(res => {
      expect(res.data.status).toBe('REQUESTED');
    });

    const req = controller.expectOne('/api/v1/maintenance-requests');
    expect(req.request.method).toBe('POST');
    expect(req.request.body).not.toHaveProperty('requested_by');
    expect(req.request.body).not.toHaveProperty('status');
    expect(req.request.body).toEqual({
      asset_id: 12,
      title: 'Servo motor vibration',
      description: 'Excessive vibration on line 2.',
      priority: 'HIGH',
    });

    req.flush(envelope(request), { status: 201, statusText: 'Created' });
  });

  it('updates a request (status + assignment, no spoofable fields)', () => {
    service.updateRequest(7, { status: 'APPROVED', assigned_to: 9 }).subscribe(res => {
      expect(res.data.status).toBe('REQUESTED');
    });

    const req = controller.expectOne('/api/v1/maintenance-requests/7');
    expect(req.request.method).toBe('PUT');
    expect(req.request.body).toEqual({ status: 'APPROVED', assigned_to: 9 });
    req.flush(envelope(request));
  });

  it('can unassign a request with a null assignment', () => {
    service.updateRequest(7, { assigned_to: null }).subscribe();

    const req = controller.expectOne('/api/v1/maintenance-requests/7');
    expect(req.request.method).toBe('PUT');
    expect(req.request.body).toEqual({ assigned_to: null });
    req.flush(envelope(request));
  });

  it('lists work records', () => {
    service.listRecords({ maintenance_request_id: 7, per_page: 5 }).subscribe(res => {
      expect(res.data.items[0].id).toBe(3);
    });

    const req = controller.expectOne(r => r.url === '/api/v1/maintenance-records');
    expect(req.request.method).toBe('GET');
    expect(req.request.params.get('maintenance_request_id')).toBe('7');
    expect(req.request.params.get('per_page')).toBe('5');
    req.flush(envelope(paginated([record])));
  });

  it('opens work via record create (Start Work) without pushing status directly', () => {
    service.createRecord({
      maintenance_request_id: 7,
      description: 'Balanced the shaft',
    }).subscribe(res => {
      expect(res.data.request?.status).toBe('IN_PROGRESS');
    });

    const req = controller.expectOne('/api/v1/maintenance-records');
    expect(req.request.method).toBe('POST');
    expect(req.request.body).not.toHaveProperty('status');
    expect(req.request.body).toEqual({ maintenance_request_id: 7, description: 'Balanced the shaft' });

    req.flush(envelope(record), { status: 201, statusText: 'Created' });
  });

  it('updates a record with work fields only', () => {
    service.updateRecord(3, { result: 'Replaced bearing', cost: 125.5 }).subscribe(res => {
      expect(res.data.cost).toBe('125.50');
    });

    const req = controller.expectOne('/api/v1/maintenance-records/3');
    expect(req.request.method).toBe('PUT');
    expect(req.request.body).toEqual({ result: 'Replaced bearing', cost: 125.5 });
    req.flush(envelope(record));
  });

  it('lists parts of a record', () => {
    service.listParts(3).subscribe(res => {
      expect(res.data.items[0].item?.sku).toBe('BRG-6205');
    });

    const req = controller.expectOne(r => r.url === '/api/v1/maintenance-records/3/parts');
    expect(req.request.method).toBe('GET');
    expect(req.request.params.get('per_page')).toBe('100');
    req.flush(envelope(paginated([part])));
  });

  it('records a part via the parts endpoint only (no stock endpoint touched)', () => {
    service.createPart(3, { item_id: 55, quantity: 2 }).subscribe(res => {
      expect(res.data.quantity).toBe(2);
    });

    const req = controller.expectOne('/api/v1/maintenance-records/3/parts');
    expect(req.request.method).toBe('POST');
    expect(req.request.body).toEqual({ item_id: 55, quantity: 2 });
    req.flush(envelope(part), { status: 201, statusText: 'Created' });
  });

  describe('mapMaintenanceError', () => {
    function httpError(status: number, body: unknown): HttpErrorResponse {
      return new HttpErrorResponse({ status, statusText: 'Error', error: body });
    }

    it('returns safe defaults for non-HTTP failures', () => {
      const info = mapMaintenanceError(new Error('boom'));
      expect(info.status).toBeNull();
      expect(info.message).toBe('Something went wrong. Please try again.');
      expect(info.fieldErrors).toEqual({});
      expect(info.accessDenied).toBe(false);
      expect(info.workflowRejected).toBe(false);
    });

    it('maps 422 validation errors to per-field messages', () => {
      const info = mapMaintenanceError(httpError(422, {
        message: 'The selected asset is invalid.',
        errors: { asset_id: ['The selected asset does not exist.'] },
      }));
      expect(info.message).toBe('Please check the highlighted fields.');
      expect(info.fieldErrors['asset_id']).toBe('The selected asset does not exist.');
      expect(info.workflowRejected).toBe(false);
    });

    it('preserves backend workflow rejections on 422', () => {
      const info = mapMaintenanceError(httpError(422, {
        message: 'Invalid maintenance status transition from APPROVED to REQUESTED',
      }));
      expect(info.message).toBe('Invalid maintenance status transition from APPROVED to REQUESTED');
      expect(info.workflowRejected).toBe(true);
    });

    it('flags work-start rejections on 422', () => {
      const info = mapMaintenanceError(httpError(422, {
        message: 'Maintenance work can only be started on an approved request.',
      }));
      expect(info.workflowRejected).toBe(true);
    });

    it('maps 403 access rejections without leaking metadata', () => {
      const info = mapMaintenanceError(httpError(403, {
        message: 'You do not have access to this maintenance request',
      }));
      expect(info.accessDenied).toBe(true);
      expect(info.message).toBe('You do not have access to this maintenance record.');
    });

    it('maps 403 without an access word to a generic message', () => {
      const info = mapMaintenanceError(httpError(403, { message: 'Forbidden.' }));
      expect(info.accessDenied).toBe(false);
      expect(info.message).toBe('This action is unauthorized.');
    });

    it('maps 404/409/429 to friendly messages', () => {
      expect(mapMaintenanceError(httpError(404, {})).message).toBe('The requested record no longer exists.');
      expect(mapMaintenanceError(httpError(409, { message: 'Still referenced' })).message).toBe('Still referenced');
      expect(mapMaintenanceError(httpError(429, {})).message).toBe('Too many attempts. Please try again later.');
    });

    it('maps network failures and 5xx', () => {
      expect(mapMaintenanceError(httpError(0, {})).message).toBe('Unable to connect to the server.');
      expect(mapMaintenanceError(httpError(500, {})).message).toBe('Something went wrong. Please try again.');
    });

    it('surfaces other backend messages verbatim', () => {
      const info = mapMaintenanceError(httpError(400, { message: 'Bad request payload.' }));
      expect(info.message).toBe('Bad request payload.');
    });
  });
});