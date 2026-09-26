import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { HttpErrorResponse } from '@angular/common/http';

import {
  TicketService,
  TicketPayload,
  TicketUpdatePayload,
  TicketCommentPayload,
  mapTicketError,
} from './ticket.service';

function envelope<T>(data: T, message = 'ok') {
  return { success: true, message, data };
}

function paginated<T>(items: T[], perPage = 15) {
  return {
    items,
    pagination: { current_page: 1, per_page: perPage, total: items.length, last_page: 1 },
  };
}

describe('TicketService', () => {
  let service: TicketService;
  let controller: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
      ],
    });
    service = TestBed.inject(TicketService);
    controller = TestBed.inject(HttpTestingController);
  });

  afterEach(() => {
    controller.verify();
  });

  const category = {
    id: 4,
    name: 'Hardware',
    code: 'HWR',
    description: null,
    tickets_count: 3,
    created_at: null,
    updated_at: null,
  };

  const user = {
    id: 9,
    name: 'Nadira',
    email: 'nadira@nexora.test',
    role: { id: 3, name: 'Technician', slug: 'technician' },
    department: { id: 2, name: 'IT', code: 'IT' },
    is_active: true,
    created_at: null,
  };

  const ticket = {
    id: 42,
    ticket_number: 'TKT-000042',
    title: 'Printer not working',
    description: 'Office printer jammed again.',
    category: { id: 4, name: 'Hardware', code: 'HWR' },
    requester: { ...user, id: 5 },
    assignee: user,
    department: { id: 1, name: 'Operations', code: 'OPS' },
    location: { id: 3, name: 'HQ Office', code: 'HQ' },
    priority: 'HIGH',
    status: 'IN_PROGRESS',
    created_at: '2025-02-10T08:00:00Z',
    updated_at: '2025-02-11T09:30:00Z',
  };

  const comment = {
    id: 1,
    comment: 'Checked the printer, replacing the drum.',
    is_internal: false,
    user,
    created_at: '2025-02-10T09:00:00Z',
    updated_at: '2025-02-10T09:00:00Z',
  };

  const history = {
    id: 1,
    action: 'STATUS_CHANGED',
    old_status: 'OPEN',
    new_status: 'IN_PROGRESS',
    notes: null,
    user,
    created_at: '2025-02-10T08:30:00Z',
  };

  it('lists tickets with status, priority, category, assignee and sort params, omitting empty ones', () => {
    service.listTickets({
      search: 'Printer',
      status: 'IN_PROGRESS',
      priority: 'HIGH',
      category_id: 4,
      assigned_to: 9,
      department_id: '',
      sort: 'created_at',
      direction: 'desc',
      page: 2,
      per_page: 15,
    }).subscribe(res => {
      expect(res.data.items).toEqual([ticket]);
    });

    const request = controller.expectOne(req => req.url === '/api/v1/tickets');
    expect(request.request.params.get('search')).toBe('Printer');
    expect(request.request.params.get('status')).toBe('IN_PROGRESS');
    expect(request.request.params.get('priority')).toBe('HIGH');
    expect(request.request.params.get('category_id')).toBe('4');
    expect(request.request.params.get('assigned_to')).toBe('9');
    expect(request.request.params.has('department_id')).toBe(false);
    expect(request.request.params.get('sort')).toBe('created_at');
    expect(request.request.params.get('direction')).toBe('desc');
    expect(request.request.params.get('page')).toBe('2');
    expect(request.request.params.get('per_page')).toBe('15');
    request.flush(envelope(paginated([ticket], 15)));
  });

  it('gets a single ticket with its relations', () => {
    service.getTicket(42).subscribe(res => {
      expect(res.data.id).toBe(42);
      expect(res.data.assignee?.name).toBe('Nadira');
    });

    const request = controller.expectOne('/api/v1/tickets/42');
    expect(request.request.method).toBe('GET');
    request.flush(envelope(ticket));
  });

  it('creates a ticket', () => {
    const payload: TicketPayload = {
      title: 'Printer not working',
      description: 'Office printer jammed again.',
      category_id: 4,
      priority: 'HIGH',
    };
    service.createTicket(payload).subscribe(res => {
      expect(res.data.ticket_number).toBe('TKT-000042');
    });

    const request = controller.expectOne('/api/v1/tickets');
    expect(request.request.method).toBe('POST');
    expect(request.request.body).toEqual(payload);
    request.flush(envelope(ticket));
  });

  it('updates a ticket, including status and assignment', () => {
    const update: TicketUpdatePayload = { status: 'CLOSED', assigned_to: 9, priority: null };
    service.updateTicket(42, update).subscribe(res => {
      expect(res.data.status).toBe('CLOSED');
    });

    const request = controller.expectOne('/api/v1/tickets/42');
    expect(request.request.method).toBe('PUT');
    expect(request.request.body).toEqual(update);
    request.flush(envelope({ ...ticket, status: 'CLOSED' }));
  });

  it('lists ticket categories with sort and pagination', () => {
    service.listCategories({ search: 'Hard', sort: 'name', direction: 'asc', page: 1, per_page: 100 }).subscribe(res => {
      expect(res.data.items.length).toBe(1);
    });

    const request = controller.expectOne(req => req.url === '/api/v1/ticket-categories');
    expect(request.request.params.get('search')).toBe('Hard');
    expect(request.request.params.get('sort')).toBe('name');
    expect(request.request.params.get('per_page')).toBe('100');
    request.flush(envelope(paginated([category], 100)));
  });

  it('creates, updates and deletes a category', () => {
    const payload = { name: 'Paper', code: 'PAP', description: null };
    service.createCategory(payload).subscribe();
    const create = controller.expectOne('/api/v1/ticket-categories');
    expect(create.request.method).toBe('POST');
    expect(create.request.body).toEqual(payload);
    create.flush(envelope({ ...category, id: 5, name: 'Paper', code: 'PAP' }));

    service.updateCategory(5, payload).subscribe();
    const update = controller.expectOne('/api/v1/ticket-categories/5');
    expect(update.request.method).toBe('PUT');
    update.flush(envelope({ ...category, id: 5 }));

    service.deleteCategory(5).subscribe(res => expect(res.success).toBe(true));
    const remove = controller.expectOne('/api/v1/ticket-categories/5');
    expect(remove.request.method).toBe('DELETE');
    remove.flush(envelope(null));
  });

  it('lists comments for a ticket with a per-page cap', () => {
    service.listComments(42).subscribe(res => {
      expect(res.data.items.length).toBe(1);
    });

    const request = controller.expectOne(req => req.url === '/api/v1/tickets/42/comments');
    expect(request.request.method).toBe('GET');
    expect(request.request.params.get('per_page')).toBe('100');
    request.flush(envelope(paginated([comment], 100)));
  });

  it('creates a comment, including internal notes', () => {
    const payload: TicketCommentPayload = { comment: 'Replace the drum.', is_internal: true };
    service.createComment(42, payload).subscribe(res => {
      expect(res.data.is_internal).toBe(true);
    });

    const request = controller.expectOne('/api/v1/tickets/42/comments');
    expect(request.request.method).toBe('POST');
    expect(request.request.body).toEqual(payload);
    request.flush(envelope({ ...comment, is_internal: true }));
  });

  it('lists history oldest-first without transforming order', () => {
    service.listHistory(42).subscribe(res => {
      expect(res.data.items).toEqual([history]);
      expect(res.data.items[0].old_status).toBe('OPEN');
    });

    const request = controller.expectOne(req => req.url === '/api/v1/tickets/42/history');
    expect(request.request.method).toBe('GET');
    expect(request.request.params.get('per_page')).toBe('100');
    request.flush(envelope(paginated([history], 100)));
  });
});

describe('mapTicketError', () => {
  function httpError(status: number, body?: unknown): HttpErrorResponse {
    return new HttpErrorResponse({ status, statusText: 'Error', error: body });
  }

  it('maps a 403 access denial without leaking backend internals', () => {
    const info = mapTicketError(httpError(403, { message: 'You do not have access to this ticket.' }));
    expect(info.accessDenied).toBe(true);
    expect(info.message).toBe('You do not have access to this request.');
  });

  it('maps a 403 without an access message as unauthorized', () => {
    const info = mapTicketError(httpError(403, { message: 'Not allowed.' }));
    expect(info.accessDenied).toBe(false);
    expect(info.message).toBe('This action is unauthorized.');
  });

  it('maps a 404 as record missing', () => {
    const info = mapTicketError(httpError(404));
    expect(info.status).toBe(404);
    expect(info.message).toContain('no longer exists');
  });

  it('extracts per-field messages from a 422 validation response', () => {
    const info = mapTicketError(httpError(422, {
      message: 'The given data was invalid.',
      errors: { title: ['The title field is required.'] },
    }));
    expect(info.fieldErrors['title']).toBe('The title field is required.');
    expect(info.message).toBe('Please check the highlighted fields.');
    expect(info.invalidTransition).toBe(false);
  });

  it('flags an invalid status transition on a message-only 422', () => {
    const info = mapTicketError(httpError(422, {
      success: false,
      message: 'Invalid status transition from CLOSED to IN_PROGRESS.',
    }));
    expect(info.fieldErrors).toEqual({});
    expect(info.status).toBe(422);
    expect(info.message).toBe('Invalid status transition from CLOSED to IN_PROGRESS.');
    expect(info.invalidTransition).toBe(true);
  });

  it('surfaces other message-only 422 business rejections without the transition flag', () => {
    const info = mapTicketError(httpError(422, { success: false, message: 'Assignee must be an active user.' }));
    expect(info.invalidTransition).toBe(false);
    expect(info.message).toBe('Assignee must be an active user.');
  });

  it('maps connection failures (status 0)', () => {
    expect(mapTicketError(httpError(0)).message).toBe('Unable to connect to the server.');
  });

  it('maps rate limiting (429)', () => {
    expect(mapTicketError(httpError(429)).message).toBe('Too many attempts. Please try again later.');
  });

  it('maps server errors (5xx) to a generic message', () => {
    expect(mapTicketError(httpError(503)).message).toBe('Something went wrong. Please try again.');
  });

  it('falls back to the backend message for other statuses', () => {
    expect(mapTicketError(httpError(400, { message: 'Bad request payload.' })).message).toBe('Bad request payload.');
  });

  it('handles unknown non-HTTP failures gracefully', () => {
    const info = mapTicketError(new Error('boom'));
    expect(info.status).toBeNull();
    expect(info.message).toBe('Something went wrong. Please try again.');
  });
});