import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { HttpErrorResponse } from '@angular/common/http';

import {
  AuditLogService,
  AuditLog,
  AuditFilters,
  mapAuditError,
  humanizeAuditValue,
  formatAuditTime,
  auditChangedRows,
  auditValueText,
  auditResourceTarget,
  AUDIT_ACTIONS,
  AUDIT_RESOURCE_TYPES,
} from './audit-log.service';

function envelope<T>(data: T, message = 'ok') {
  return { success: true, message, data };
}

function paginated<T>(items: T[], total = items.length, perPage = 25) {
  return {
    items,
    pagination: { current_page: 1, per_page: perPage, total, last_page: 1 },
  };
}

const auditLog: AuditLog = {
  id: 42,
  actor: { id: 12, name: 'Admin' },
  action: 'status_changed',
  resource: { type: 'ticket', id: 7 },
  description: 'Ticket TCK-1 status changed from OPEN to IN_PROGRESS',
  old_values: { status: 'OPEN' },
  new_values: { status: 'IN_PROGRESS' },
  ip_address: '127.0.0.1',
  user_agent: 'Mozilla/5.0',
  created_at: '2026-09-26T10:15:00.000000Z',
};

describe('AuditLogService', () => {
  let service: AuditLogService;
  let controller: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
    service = TestBed.inject(AuditLogService);
    controller = TestBed.inject(HttpTestingController);
  });

  afterEach(() => {
    controller.verify();
  });

  it('lists audit logs with pagination params', () => {
    const filters: AuditFilters = { page: 2, per_page: 25 };
    service.getAuditLogs(filters).subscribe(res => {
      expect(res.data.items.length).toBe(1);
      expect(res.data.pagination.total).toBe(1);
    });

    const req = controller.expectOne(r => r.url === '/api/v1/audit-logs');
    expect(req.request.method).toBe('GET');
    expect(req.request.params.get('page')).toBe('2');
    expect(req.request.params.get('per_page')).toBe('25');
    req.flush(envelope(paginated([auditLog])));
  });

  it('sends the actor filter', () => {
    service.getAuditLogs({ actor_id: 12 }).subscribe();
    const req = controller.expectOne(r => r.url === '/api/v1/audit-logs');
    expect(req.request.params.get('actor_id')).toBe('12');
    req.flush(envelope(paginated([])));
  });

  it('sends the action filter with the exact backend value', () => {
    service.getAuditLogs({ action: 'status_changed' }).subscribe();
    const req = controller.expectOne(r => r.url === '/api/v1/audit-logs');
    expect(req.request.params.get('action')).toBe('status_changed');
    req.flush(envelope(paginated([])));
  });

  it('sends the resource type and resource id filters', () => {
    service.getAuditLogs({ resource_type: 'ticket', resource_id: 7 }).subscribe();
    const req = controller.expectOne(r => r.url === '/api/v1/audit-logs');
    expect(req.request.params.get('resource_type')).toBe('ticket');
    expect(req.request.params.get('resource_id')).toBe('7');
    req.flush(envelope(paginated([])));
  });

  it('sends an inclusive from/to date range', () => {
    service.getAuditLogs({ from: '2026-01-01', to: '2026-01-31' }).subscribe();
    const req = controller.expectOne(r => r.url === '/api/v1/audit-logs');
    expect(req.request.params.get('from')).toBe('2026-01-01');
    expect(req.request.params.get('to')).toBe('2026-01-31');
    req.flush(envelope(paginated([])));
  });

  it('omits undefined, null and empty-string filters entirely', () => {
    service.getAuditLogs({
      actor_id: undefined,
      action: '',
      resource_type: null as unknown as string,
    }).subscribe();

    const req = controller.expectOne(r => r.url === '/api/v1/audit-logs');
    expect(req.request.params.keys()).toEqual([]);
    req.flush(envelope(paginated([])));
  });

  it('fetches a single audit log by id', () => {
    service.getAuditLog(42).subscribe(res => {
      expect(res.data.id).toBe(42);
      expect(res.data.actor?.name).toBe('Admin');
    });

    const req = controller.expectOne('/api/v1/audit-logs/42');
    expect(req.request.method).toBe('GET');
    req.flush(envelope(auditLog));
  });
});

describe('mapAuditError', () => {
  function httpError(status: number, body: unknown): HttpErrorResponse {
    return new HttpErrorResponse({ status, statusText: 'Error', error: body });
  }

  it('returns safe defaults for non-HTTP failures', () => {
    const info = mapAuditError(new Error('boom'));
    expect(info.status).toBeNull();
    expect(info.message).toBe('Something went wrong. Please try again.');
  });

  it('maps status codes to friendly messages', () => {
    expect(mapAuditError(httpError(401, {})).message).toBe('Your session has expired. Please sign in again.');
    expect(mapAuditError(httpError(403, {})).message).toBe('You do not have access to governance audit logs.');
    expect(mapAuditError(httpError(404, {})).message).toBe('This audit event no longer exists.');
    expect(mapAuditError(httpError(422, {})).message).toBe('The requested date range or filters are invalid.');
    expect(mapAuditError(httpError(429, {})).message).toBe('Too many attempts. Please try again later.');
  });

  it('maps network failures and 5xx', () => {
    expect(mapAuditError(httpError(0, {})).message).toBe('Unable to connect to the server.');
    expect(mapAuditError(httpError(503, {})).message).toBe('Something went wrong. Please try again.');
  });
});

describe('audit presentation helpers', () => {
  it('exposes the Phase 20A backend vocabularies', () => {
    expect(AUDIT_ACTIONS).toEqual([
      'created', 'updated', 'deleted', 'status_changed',
      'assigned', 'returned', 'registered', 'logged_in', 'logged_out',
    ]);
    expect(AUDIT_RESOURCE_TYPES).toContain('ticket');
    expect(AUDIT_RESOURCE_TYPES).toContain('maintenance_request');
    expect(AUDIT_RESOURCE_TYPES).not.toContain('AuditLog'); // never class names
  });

  it('humanizes known snake_case values for display only', () => {
    expect(humanizeAuditValue('status_changed')).toBe('Status changed');
    expect(humanizeAuditValue('created')).toBe('Created');
    expect(humanizeAuditValue('logged_in')).toBe('Logged in');
    expect(humanizeAuditValue('maintenance_request')).toBe('Maintenance request');
  });

  it('falls back safely for unknown future values', () => {
    expect(humanizeAuditValue('policy_changed')).toBe('Policy changed');
    expect(humanizeAuditValue('weird__value')).toBe('Weird value');
  });

  it('formats timestamps compactly and degrades for invalid input', () => {
    expect(formatAuditTime(null)).toBe('—');
    expect(formatAuditTime('not-a-date')).toBe('not-a-date');
    expect(formatAuditTime('2026-09-26T10:15:00Z')).toMatch(/Sep/);
  });

  it('renders values as compact text without crashing on exotic input', () => {
    expect(auditValueText(null)).toBe('—');
    expect(auditValueText(undefined)).toBe('—');
    expect(auditValueText('')).toBe('—');
    expect(auditValueText('OPEN')).toBe('OPEN');
    expect(auditValueText(42)).toBe('42');
    expect(auditValueText(true)).toBe('true');
    expect(auditValueText({ nested: true })).toBe('{"nested":true}');
  });

  it('computes changed-field rows from old/new values', () => {
    const rows = auditChangedRows({ status: 'OPEN', priority: 'MEDIUM' }, { status: 'IN_PROGRESS', priority: 'MEDIUM' });
    expect(rows).toEqual([{ field: 'Status', old: 'OPEN', new: 'IN_PROGRESS' }]);
  });

  it('renders one-sided values with an em dash on the missing side', () => {
    const rows = auditChangedRows({ status: 'OPEN' }, { status: 'IN_PROGRESS', assignee: 'Admin' });
    expect(rows).toContainEqual({ field: 'Status', old: 'OPEN', new: 'IN_PROGRESS' });
    expect(rows).toContainEqual({ field: 'Assignee', old: '—', new: 'Admin' });
  });

  it('returns no rows when both sides are absent', () => {
    expect(auditChangedRows(null, null)).toEqual([]);
  });
});

describe('auditResourceTarget', () => {
  it('maps verified resource types to workspace deep links', () => {
    expect(auditResourceTarget('ticket', 7)).toEqual({ path: '/requests', queryParams: { selected: 7 } });
    expect(auditResourceTarget('maintenance_request', 3)).toEqual({ path: '/maintenance', queryParams: { selected: 3 } });
    expect(auditResourceTarget('asset', 18)).toEqual({ path: '/assets', queryParams: { selected: 18 } });
    expect(auditResourceTarget('item', 5)).toEqual({ path: '/inventory', queryParams: { selected: 5 } });
  });

  it('never maps asset_assignment ids to /assets (id semantics differ)', () => {
    expect(auditResourceTarget('asset_assignment', 9)).toBeNull();
  });

  it('returns null for unknown resource types and invalid ids', () => {
    expect(auditResourceTarget('department', 4)).toBeNull();
    expect(auditResourceTarget('ticket', null)).toBeNull();
    expect(auditResourceTarget('ticket', 0)).toBeNull();
    expect(auditResourceTarget('ticket', -3)).toBeNull();
  });
});
