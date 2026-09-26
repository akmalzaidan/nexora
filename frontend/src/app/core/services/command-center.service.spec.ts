import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { HttpErrorResponse } from '@angular/common/http';

import {
  CommandCenterPayload,
  CommandCenterService,
  COMMAND_CENTER_DEFAULT_LIMIT,
  mapCommandCenterError,
} from './command-center.service';

function envelope<T>(data: T, message = 'ok') {
  return { success: true, message, data };
}

function httpError(status: number, body: unknown = null): HttpErrorResponse {
  return new HttpErrorResponse({ status, error: body, statusText: 'Error' });
}

/** A fully-populated payload: every optional section present. */
const fullPayload: CommandCenterPayload = {
  generated_at: '2026-09-26T04:15:43.324799Z',
  snapshot: {
    assets: { total: 48, active: 41, in_maintenance: 5, unassigned: 12 },
    inventory: { item_count: 30, warehouse_count: 3, stock_quantity: 812 },
    tickets: { total: 64, active: 22, unassigned: 9 },
    maintenance: { total: 27, active: 11, unassigned: 6, awaiting_approval: 4 },
    notifications: { unread_count: 3 },
  },
  queues: {
    unassigned_tickets: {
      count: 9,
      limit: 10,
      items: [
        {
          id: 91,
          type: 'ticket',
          reference: 'TCK-7F3A9K2M',
          title: 'Projector lamp flickering',
          status: 'OPEN',
          created_at: '2026-09-26T08:02:00.000000Z',
        },
      ],
    },
    unassigned_maintenance_requests: { count: 6, limit: 10, items: [] },
    pending_asset_assignments: { count: 2, limit: 10, items: [] },
  },
  recent_activity: {
    limit: 10,
    items: [
      {
        type: 'maintenance',
        action: 'WORK_COMPLETED',
        occurred_at: '2026-09-26T08:44:00.000000Z',
        record_id: 18,
        reference: 'AST-000112',
        label: 'Replace projector lamp',
        status: 'COMPLETED',
        quantity: null,
        old_status: null,
        new_status: null,
      },
    ],
  },
};

/** A `warehouse_staff` payload: the backend omits every unreadable section. */
const scopedPayload: CommandCenterPayload = {
  generated_at: '2026-09-26T04:15:43.324799Z',
  snapshot: {
    inventory: { item_count: 30, warehouse_count: 3, stock_quantity: 812 },
    notifications: { unread_count: 0 },
  },
  queues: {
    pending_asset_assignments: { count: 2, limit: 10, items: [] },
  },
  recent_activity: { limit: 10, items: [] },
};

describe('CommandCenterService', () => {
  let service: CommandCenterService;
  let controller: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
    service = TestBed.inject(CommandCenterService);
    controller = TestBed.inject(HttpTestingController);
  });

  afterEach(() => {
    controller.verify();
  });

  it('reads the snapshot from the command-center endpoint with a GET', () => {
    let received: CommandCenterPayload | null = null;
    service.getSnapshot().subscribe(res => {
      received = res.data;
    });

    const req = controller.expectOne(r => r.url === '/api/v1/command-center' && r.method === 'GET');
    expect(req.request.params.keys()).toEqual([]);
    req.flush(envelope(fullPayload, 'Command Center snapshot'));

    expect(received).toEqual(fullPayload);
  });

  it('never sends a date window: the endpoint is a current-state surface', () => {
    service.getSnapshot(25).subscribe();

    const req = controller.expectOne(r => r.url === '/api/v1/command-center');
    expect(req.request.params.has('from')).toBe(false);
    expect(req.request.params.has('to')).toBe(false);
    expect(req.request.params.get('limit')).toBe('25');
    req.flush(envelope(fullPayload));
  });

  it('sends no limit by default so the backend default applies', () => {
    service.getSnapshot().subscribe();

    const req = controller.expectOne(r => r.url === '/api/v1/command-center');
    expect(req.request.params.has('limit')).toBe(false);
    req.flush(envelope(fullPayload));
  });

  it('passes the payload through unchanged, including omitted sections', () => {
    let received: CommandCenterPayload | null = null;
    service.getSnapshot(COMMAND_CENTER_DEFAULT_LIMIT).subscribe(res => {
      received = res.data;
    });

    const req = controller.expectOne(r => r.url === '/api/v1/command-center');
    req.flush(envelope(scopedPayload));

    // An omitted section must stay omitted, never be coerced to zeroes.
    expect(received!.snapshot.assets).toBeUndefined();
    expect(received!.snapshot.tickets).toBeUndefined();
    expect(received!.snapshot.notifications.unread_count).toBe(0);
    expect(received!.queues.pending_asset_assignments!.count).toBe(2);
  });

  it('issues exactly one request per snapshot call', () => {
    service.getSnapshot().subscribe();
    service.getSnapshot().subscribe();

    const requests = controller.match(r => r.url === '/api/v1/command-center');
    expect(requests.length).toBe(2);
    requests.forEach(req => req.flush(envelope(fullPayload)));
  });

  it('surfaces a 401 as an expired session', () => {
    let failure: unknown = null;
    service.getSnapshot().subscribe({ error: err => (failure = err) });

    controller.expectOne(r => r.url === '/api/v1/command-center').flush(null, {
      status: 401,
      statusText: 'Unauthorized',
    });

    expect(failure).toBeInstanceOf(HttpErrorResponse);
    const info = mapCommandCenterError(failure);
    expect(info.status).toBe(401);
    expect(info.accessDenied).toBe(false);
    expect(info.message).toBe('Your session has expired. Please sign in again.');
  });

  it('surfaces a 403 as an access-restricted state', () => {
    let failure: unknown = null;
    service.getSnapshot().subscribe({ error: err => (failure = err) });

    controller.expectOne(r => r.url === '/api/v1/command-center').flush(null, {
      status: 403,
      statusText: 'Forbidden',
    });

    const info = mapCommandCenterError(failure);
    expect(info.status).toBe(403);
    expect(info.accessDenied).toBe(true);
  });

  it('surfaces a 422 with per-field messages and no invented copy', () => {
    let failure: unknown = null;
    service.getSnapshot(99).subscribe({ error: err => (failure = err) });

    controller.expectOne(r => r.url === '/api/v1/command-center').flush(
      { success: false, message: 'The given data was invalid.', errors: { limit: ['The limit field must not be greater than 50.'] } },
      { status: 422, statusText: 'Unprocessable' },
    );

    const info = mapCommandCenterError(failure);
    expect(info.status).toBe(422);
    expect(info.accessDenied).toBe(false);
    expect(info.fieldErrors['limit']).toBe('The limit field must not be greater than 50.');
  });

  it('surfaces a 500 as a server failure', () => {
    let failure: unknown = null;
    service.getSnapshot().subscribe({ error: err => (failure = err) });

    controller.expectOne(r => r.url === '/api/v1/command-center').flush(null, {
      status: 500,
      statusText: 'Server Error',
    });

    const info = mapCommandCenterError(failure);
    expect(info.status).toBe(500);
    expect(info.accessDenied).toBe(false);
    expect(info.message).toBe('The server could not build the snapshot. Please try again.');
  });

  it('surfaces a network failure as a connection problem', () => {
    let failure: unknown = null;
    service.getSnapshot().subscribe({ error: err => (failure = err) });

    controller
      .expectOne(r => r.url === '/api/v1/command-center')
      .error(new ProgressEvent('error'), { status: 0, statusText: 'Unknown Error' });

    const info = mapCommandCenterError(failure);
    expect(info.status).toBe(0);
    expect(info.message).toBe('Unable to connect to the server.');
  });

  it('falls back to safe copy for an error that is not an HTTP failure', () => {
    const info = mapCommandCenterError(new Error('boom'));
    expect(info.status).toBeNull();
    expect(info.accessDenied).toBe(false);
    expect(info.message).toBe('Unable to load the Command Center.');
  });
});
