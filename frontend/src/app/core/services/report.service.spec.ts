import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { HttpErrorResponse } from '@angular/common/http';

import {
  ReportService,
  ReportOverview,
  ReportPeriod,
  mapReportError,
  validateReportPeriod,
} from './report.service';

function envelope<T>(data: T, message = 'ok') {
  return { success: true, message, data };
}

const overview: ReportOverview = {
  generated_at: '2026-03-01T10:15:00+00:00',
  assets: { total: 3, by_status: [{ status: 'ACTIVE', count: 3 }] },
  inventory: { item_count: 4, warehouse_count: 2, stock_quantity: 57 },
  tickets: { total: 1, by_status: [{ status: 'OPEN', count: 1 }] },
  maintenance: { total: 0, by_status: [] },
};

function httpError(status: number, body: unknown = null): HttpErrorResponse {
  return new HttpErrorResponse({ status, error: body, statusText: 'Error' });
}

describe('ReportService', () => {
  let service: ReportService;
  let controller: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
    service = TestBed.inject(ReportService);
    controller = TestBed.inject(HttpTestingController);
  });

  afterEach(() => {
    controller.verify();
  });

  it('reads the overview from the snapshot endpoint without any period parameter', () => {
    let received: ReportOverview | null = null;
    service.getOverview().subscribe(res => {
      received = res.data;
    });

    const req = controller.expectOne(r => r.url === '/api/v1/reports/overview' && r.method === 'GET');
    expect(req.request.params.keys()).toEqual([]);
    req.flush(envelope(overview, 'Operational overview'));

    expect(received).toEqual(overview);
  });

  it('reads a detail report with no parameters in the default current state', () => {
    service.getAssets().subscribe();

    const req = controller.expectOne(r => r.url === '/api/v1/reports/assets');
    expect(req.request.params.keys()).toEqual([]);
    req.flush(envelope({}));
  });

  it('sends the period and ranking limit exactly as given', () => {
    service.getAssets({ from: '2026-03-01', to: '2026-03-31', limit: 25 }).subscribe();

    const req = controller.expectOne(r => r.url === '/api/v1/reports/assets');
    expect(req.request.params.get('from')).toBe('2026-03-01');
    expect(req.request.params.get('to')).toBe('2026-03-31');
    expect(req.request.params.get('limit')).toBe('25');
    req.flush(envelope({}));
  });

  it('never sends a half period: a lone bound is dropped rather than rejected later', () => {
    service.getTickets({ from: '2026-03-01' }).subscribe();

    const req = controller.expectOne(r => r.url === '/api/v1/reports/tickets');
    expect(req.request.params.has('from')).toBe(false);
    expect(req.request.params.has('to')).toBe(false);
    req.flush(envelope({}));
  });

  it('sends a limit without a period', () => {
    service.getInventory({ limit: 5 }).subscribe();

    const req = controller.expectOne(r => r.url === '/api/v1/reports/inventory');
    expect(req.request.params.get('limit')).toBe('5');
    expect(req.request.params.has('from')).toBe(false);
    req.flush(envelope({}));
  });

  it('reads the inventory, ticket, and maintenance report endpoints', () => {
    service.getInventory().subscribe();
    service.getTickets().subscribe();
    service.getMaintenance({ from: '2026-01-01', to: '2026-01-02' }).subscribe();

    expect(controller.match(r => r.url === '/api/v1/reports/inventory').length).toBe(1);
    expect(controller.match(r => r.url === '/api/v1/reports/tickets').length).toBe(1);

    const maintenance = controller.match(r => r.url === '/api/v1/reports/maintenance')[0];
    expect(maintenance.request.params.get('from')).toBe('2026-01-01');

    controller.match(r => r.url.startsWith('/api/v1/reports/')).forEach(req =>
      req.flush(envelope({})),
    );
  });

  it('passes the payload through unchanged so no value is reshaped in the client', () => {
    const payload = {
      current: {
        total: 350,
        by_status: [{ status: 'ACTIVE', count: 350 }],
        by_category: [{ category_id: 7, label: 'Laptop', count: 350 }],
        by_location: [],
        assigned_count: 120,
        unassigned_count: 230,
        without_location_count: 12,
      },
      assignments: { total: 40, by_status: [{ status: 'RETURNED', count: 40 }] },
      period: {
        from: '2026-03-01',
        to: '2026-03-31',
        assets_created: 8,
        assignments_assigned: 3,
        assignments_returned: 2,
      },
    };

    let received: unknown = null;
    service.getAssets().subscribe(res => {
      received = res.data;
    });
    controller.expectOne(r => r.url === '/api/v1/reports/assets').flush(envelope(payload));

    expect(received).toEqual(payload);
  });
});

describe('validateReportPeriod', () => {
  it('accepts the empty period as a valid current-state request', () => {
    expect(validateReportPeriod('', '')).toBeNull();
  });

  it('accepts a single-day period', () => {
    expect(validateReportPeriod('2026-03-01', '2026-03-01')).toBeNull();
  });

  it('accepts exactly 366 inclusive days', () => {
    expect(validateReportPeriod('2026-01-01', '2027-01-01')).toBeNull();
  });

  it('rejects a range longer than 366 days', () => {
    expect(validateReportPeriod('2026-01-01', '2027-01-02')).toContain('366');
  });

  it('rejects a half-filled period', () => {
    expect(validateReportPeriod('2026-03-01', '')).toBeTruthy();
    expect(validateReportPeriod('', '2026-03-01')).toBeTruthy();
  });

  it('rejects a reversed range', () => {
    expect(validateReportPeriod('2026-03-31', '2026-03-01')).toBeTruthy();
  });

  it('rejects a non ISO date', () => {
    expect(validateReportPeriod('01/03/2026', '2026-03-31')).toContain('YYYY-MM-DD');
  });

  it('rejects a well-formatted day that does not exist', () => {
    // `Date.parse` would silently roll these over to the next valid month.
    expect(validateReportPeriod('2026-02-31', '2026-03-31')).toContain('YYYY-MM-DD');
    expect(validateReportPeriod('2026-04-31', '2026-05-01')).toContain('YYYY-MM-DD');
    expect(validateReportPeriod('2026-13-01', '2026-13-31')).toContain('YYYY-MM-DD');
    expect(validateReportPeriod('2026-01-00', '2026-01-31')).toContain('YYYY-MM-DD');
  });

  it('accepts a real leap day and rejects a fake one', () => {
    expect(validateReportPeriod('2028-02-29', '2028-03-01')).toBeNull();
    expect(validateReportPeriod('2026-02-29', '2026-03-01')).toContain('YYYY-MM-DD');
  });

  it('ignores surrounding whitespace', () => {
    expect(validateReportPeriod(' 2026-03-01 ', ' 2026-03-02 ')).toBeNull();
  });
});

describe('mapReportError', () => {
  it('flags an expired session', () => {
    const info = mapReportError(httpError(401));
    expect(info.status).toBe(401);
    expect(info.accessDenied).toBe(false);
    expect(info.message).toContain('session');
  });

  it('marks 403 as an access-restricted state', () => {
    const info = mapReportError(httpError(403));
    expect(info.accessDenied).toBe(true);
    expect(info.message).toContain('access');
  });

  it('reports a missing report', () => {
    expect(mapReportError(httpError(404)).status).toBe(404);
  });

  it('keeps the backend message for a plain 422 period rejection', () => {
    const info = mapReportError(
      httpError(422, { message: 'The report period may not exceed 366 days.' }),
    );
    expect(info.status).toBe(422);
    expect(info.message).toBe('The report period may not exceed 366 days.');
  });

  it('extracts the first field message from a 422 validation payload', () => {
    const info = mapReportError(
      httpError(422, {
        message: 'The given data was invalid.',
        errors: { to: ['The from date must be on or before the to date.'] },
      }),
    );
    expect(info.fieldErrors['to']).toBe('The from date must be on or before the to date.');
    expect(info.message).toBeTruthy();
  });

  it('reports throttling and server faults without leaking internals', () => {
    expect(mapReportError(httpError(429)).message).toContain('Too many requests');
    expect(mapReportError(httpError(500, { message: 'SQLSTATE[42S02]' })).message).not.toContain(
      'SQLSTATE',
    );
    expect(mapReportError(httpError(503)).message).toBeTruthy();
  });

  it('reports an offline failure', () => {
    expect(mapReportError(httpError(0)).message).toContain('connect');
  });

  it('falls back safely for a non-HTTP failure', () => {
    const info = mapReportError(new Error('boom'));
    expect(info.status).toBeNull();
    expect(info.message).toBeTruthy();
  });
});

describe('ReportPeriod', () => {
  it('is a plain inclusive range', () => {
    const period: ReportPeriod = { from: '2026-03-01', to: '2026-03-31' };
    expect(period.from < period.to).toBe(true);
  });
});
