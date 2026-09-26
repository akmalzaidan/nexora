import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { provideRouter } from '@angular/router';
import { RouterTestingHarness } from '@angular/router/testing';
import { IonicModule } from '@ionic/angular/lazy';

import { AuthService } from '../../core/services/auth.service';
import { StorageService } from '../../core/services/storage.service';
import { Ticket, TicketCategory, TicketComment, TicketHistory } from '../../core/services/ticket.service';
import { ManagedUser } from '../../core/services/asset.service';
import { RequestsPage } from './requests.page';

const routes = [{ path: 'requests', component: RequestsPage }];

function user(roleSlug: string) {
  return {
    id: 1,
    name: 'Tester',
    email: 'tester@nexora.test',
    role: { id: 1, name: 'Role', slug: roleSlug },
    department: null,
    is_active: true,
    created_at: null,
  };
}

function paged<T>(items: T[], perPage = 15): { success: true; message: string; data: { items: T[]; pagination: { current_page: number; per_page: number; total: number; last_page: number } } } {
  return {
    success: true,
    message: 'ok',
    data: {
      items,
      pagination: { current_page: 1, per_page: perPage, total: items.length, last_page: 1 },
    },
  };
}

describe('RequestsPage', () => {
  let controller: HttpTestingController;
  let harness: RouterTestingHarness;

  async function createPage(roleSlug: string): Promise<RequestsPage> {
    const storage = TestBed.inject(StorageService);
    const auth = TestBed.inject(AuthService);
    storage.set('nx-token', 'test-token');
    const init = auth.ensureInitialized();
    controller.expectOne('/api/v1/auth/me').flush({
      success: true,
      message: 'Authenticated user',
      data: { user: user(roleSlug) },
    });
    await init;

    harness = await RouterTestingHarness.create();
    return harness.navigateByUrl('/requests', RequestsPage);
  }

function flushInit(tickets: Ticket[] = [], categories: TicketCategory[] = [], users: unknown[] = [managedUserFixture]): void {
    controller.expectOne(req => req.url === '/api/v1/tickets').flush(paged(tickets, 15));
    controller.expectOne(req => req.url === '/api/v1/ticket-categories').flush(paged(categories, 100));
    controller.match(req => req.url === '/api/v1/users').forEach(req => req.flush(paged(users, 100)));
  }

  function flushDetailAll(): void {
    controller.expectOne('/api/v1/tickets/42').flush({
      success: true,
      message: 'ok',
      data: ticketFixture,
    });
    controller.expectOne(req => req.url === '/api/v1/tickets/42/comments').flush(paged([commentFixture], 100));
    controller.expectOne(req => req.url === '/api/v1/tickets/42/history').flush(paged([historyFixture], 100));
  }

  function flushAfterChange(): void {
    controller.expectOne('/api/v1/tickets/42').flush({
      success: true,
      message: 'ok',
      data: ticketFixture,
    });
    controller.expectOne(req => req.url === '/api/v1/tickets/42/history').flush(paged([historyFixture], 100));
    controller.expectOne(req => req.url === '/api/v1/tickets').flush(paged([ticketFixture], 15));
  }

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [IonicModule.forRoot()],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        provideRouter(routes),
      ],
    }).compileComponents();
    controller = TestBed.inject(HttpTestingController);
    localStorage.clear();
  });

  afterEach(() => {
    controller.verify();
    localStorage.clear();
  });

  it('renders the header, summary strip and ticket rows for an agent', async () => {
    const component = await createPage('super_admin');
    flushInit([ticketFixture], [categoryFixture], [managedUserFixture]);
    harness.fixture.detectChanges();

    const text = harness.fixture.nativeElement.textContent;
    expect(text).toContain('Requests');
    expect(text).toContain('Hardware');
    expect(harness.fixture.nativeElement.querySelectorAll('.nx-summary-item').length).toBe(2);
    const rows = harness.fixture.nativeElement.querySelectorAll('.nx-item-row');
    expect(rows.length).toBe(1);
    expect(rows[0].textContent).toContain('TKT-000042');
    expect(rows[0].textContent).toContain('Printer not working');
    expect(component.tickets().length).toBe(1);
    expect(component.ticketsTotal()).toBe(1);
    expect(component.isAgent()).toBe(true);
  });

  it('renders the empty state when the backend returns no tickets', async () => {
    await createPage('super_admin');
    flushInit();
    harness.fixture.detectChanges();

    const text = harness.fixture.nativeElement.textContent;
    expect(text).toContain('NO REQUESTS YET');
    expect(harness.fixture.nativeElement.querySelectorAll('.nx-item-row').length).toBe(0);
  });

  it('renders an error state and retries the list', async () => {
    const component = await createPage('super_admin');
    controller.expectOne(req => req.url === '/api/v1/ticket-categories').flush(paged([], 100));
    controller.expectOne(req => req.url === '/api/v1/users').flush(paged([], 100));
    controller.expectOne(req => req.url === '/api/v1/tickets').flush(
      { success: false, message: 'Server exploded.' },
      { status: 500, statusText: 'Server Error' },
    );
    harness.fixture.detectChanges();

    expect(component.ticketsError()).not.toBeNull();
    expect(harness.fixture.nativeElement.textContent).toContain('UNABLE TO LOAD REQUESTS');

    const retryButton = Array.from<HTMLButtonElement>(harness.fixture.nativeElement.querySelectorAll('button')).find(
      b => b.textContent?.trim() === 'Retry',
    );
    retryButton?.dispatchEvent(new MouseEvent('click'));
    controller.expectOne(req => req.url === '/api/v1/tickets').flush(paged([ticketFixture], 15));
    harness.fixture.detectChanges();

    expect(component.ticketsError()).toBeNull();
    expect(harness.fixture.nativeElement.querySelectorAll('.nx-item-row').length).toBe(1);
  });

  it('shows the New Request button for viewers', async () => {
    await createPage('staff');
    flushInit();
    harness.fixture.detectChanges();
    expect(harness.fixture.nativeElement.querySelector('.nx-page-header').textContent).toContain('New Request');
  });

  it('keeps the Categories tab hidden and does not expose agent controls for staff', async () => {
    const component = await createPage('staff');
    flushInit([ticketFixture], [categoryFixture]);
    harness.fixture.detectChanges();

    expect(harness.fixture.nativeElement.querySelectorAll('.nx-tab').length).toBe(1);
    expect(component.isAgent()).toBe(false);

    component.selectTicket(42);
    flushDetailAll();
    harness.fixture.detectChanges();

    const detailText: string = harness.fixture.nativeElement.querySelector('.nx-detail')?.textContent ?? '';
    expect(detailText).toContain('Printer not working');
    expect(detailText).not.toContain('Resolve');
    expect(detailText).not.toContain('Reassign');
    expect(detailText).not.toContain('Remove');
    expect(detailText).not.toContain('Internal note');
  });

  it('selecting a ticket renders its detail, comments, history and workflow controls', async () => {
    const component = await createPage('super_admin');
    flushInit([ticketFixture], [categoryFixture], [managedUserFixture]);
    harness.fixture.detectChanges();

    component.selectTicket(42);
    flushDetailAll();
    harness.fixture.detectChanges();

    expect(component.selectedTicketId()).toBe(42);
    expect(component.comments().length).toBe(1);
    expect(component.history().length).toBe(1);

    const detailText: string = harness.fixture.nativeElement.querySelector('.nx-detail')?.textContent ?? '';
    expect(detailText).toContain('TKT-000042');
    expect(detailText).toContain('In Progress');
    expect(detailText).toContain('High');
    expect(detailText).toContain('Nadira');
    expect(detailText).toContain('Checked the printer, replacing the drum.');
    expect(detailText).toContain('Status changed');
    expect(detailText).toContain('Open');
    expect(detailText).toContain('Resolve');
    expect(detailText).toContain('Reassign');
  });

  it('advances a ticket status after confirmation', async () => {
    const component = await createPage('super_admin');
    flushInit([ticketFixture], [categoryFixture], [managedUserFixture]);
    harness.fixture.detectChanges();

    component.selectTicket(42);
    flushDetailAll();
    harness.fixture.detectChanges();

    component.requestStatusChange(ticketFixture);
    expect(component.confirmRequest()).not.toBeNull();
    component.executeConfirm();

    const update = controller.expectOne(req => req.method === 'PUT' && req.url === '/api/v1/tickets/42');
    expect(update.request.body).toEqual({ status: 'RESOLVED' });
    update.flush({ success: true, message: 'ok', data: { ...ticketFixture, status: 'RESOLVED' } });
    flushAfterChange();
    harness.fixture.detectChanges();

    expect(component.confirmRequest()).toBeNull();
    expect(component.detailTicket()?.status).toBe('IN_PROGRESS');
  });

  it('treats an invalid status transition as a sync with the backend', async () => {
    const component = await createPage('super_admin');
    flushInit([ticketFixture], [categoryFixture], [managedUserFixture]);
    harness.fixture.detectChanges();

    component.selectTicket(42);
    flushDetailAll();
    harness.fixture.detectChanges();

    component.requestStatusChange(ticketFixture);
    component.executeConfirm();

    controller.expectOne(req => req.method === 'PUT' && req.url === '/api/v1/tickets/42').flush(
      { success: false, message: 'Invalid status transition from IN_PROGRESS to RESOLVED.' },
      { status: 422, statusText: 'Unprocessable Entity' },
    );
    controller.expectOne('/api/v1/tickets/42').flush({ success: true, message: 'ok', data: ticketFixture });
    controller.expectOne(req => req.url === '/api/v1/tickets/42/history').flush(paged([historyFixture], 100));
    harness.fixture.detectChanges();

    expect(component.confirmRequest()).toBeNull();
    expect(component.detailTicket()?.status).toBe('IN_PROGRESS');
  });

  it('creates a request and selects the new ticket', async () => {
    const component = await createPage('super_admin');
    flushInit([], [categoryFixture], [managedUserFixture]);
    harness.fixture.detectChanges();

    component.openCreateTicketForm();
    component.ticketForm.patchValue({ title: 'Laptop dead', description: 'Will not power on.', priority: 'URGENT' });
    component.submitTicketForm();

    const create = controller.expectOne(req => req.method === 'POST' && req.url === '/api/v1/tickets');
    expect(create.request.body).toEqual({
      title: 'Laptop dead',
      description: 'Will not power on.',
      category_id: null,
      priority: 'URGENT',
    });
    create.flush({
      success: true,
      message: 'Request created',
      data: { ...ticketFixture, id: 43, ticket_number: 'TKT-000043', title: 'Laptop dead', priority: 'URGENT' },
    });
    controller.expectOne(req => req.url === '/api/v1/tickets').flush(paged([ticketFixture], 15));
    controller.expectOne('/api/v1/tickets/43').flush({
      success: true,
      message: 'ok',
      data: { ...ticketFixture, id: 43, ticket_number: 'TKT-000043', title: 'Laptop dead', priority: 'URGENT' },
    });
    controller.expectOne(req => req.url === '/api/v1/tickets/43/comments').flush(paged([], 100));
    controller.expectOne(req => req.url === '/api/v1/tickets/43/history').flush(paged([historyFixture], 100));
    harness.fixture.detectChanges();

    expect(component.isTicketFormOpen()).toBe(false);
    expect(component.selectedTicketId()).toBe(43);
  });

  it('blocks request submission with client-side validation errors', async () => {
    const component = await createPage('super_admin');
    flushInit();
    harness.fixture.detectChanges();

    component.openCreateTicketForm();
    component.submitTicketForm();

    expect(component.isTicketFormOpen()).toBe(true);
    expect(component.ticketErrors()['title']).toBeTruthy();
    expect(component.ticketErrors()['description']).toBeTruthy();
  });

  it('updates an existing request', async () => {
    const component = await createPage('super_admin');
    flushInit([ticketFixture], [categoryFixture], [managedUserFixture]);
    harness.fixture.detectChanges();

    component.openEditTicketForm(ticketFixture);
    component.ticketForm.patchValue({ title: 'Printer fully dead' });
    component.submitTicketForm();

    const update = controller.expectOne(req => req.method === 'PUT' && req.url === '/api/v1/tickets/42');
    expect(update.request.body).toEqual({
      title: 'Printer fully dead',
      description: 'Office printer jammed again.',
      category_id: 1,
      priority: 'HIGH',
    });
    update.flush({ success: true, message: 'Request updated', data: { ...ticketFixture, title: 'Printer fully dead' } });
    controller.expectOne('/api/v1/tickets/42').flush({ success: true, message: 'ok', data: ticketFixture });
    controller.expectOne(req => req.url === '/api/v1/tickets/42/history').flush(paged([historyFixture], 100));
    controller.expectOne(req => req.url === '/api/v1/tickets').flush(paged([ticketFixture], 15));
    harness.fixture.detectChanges();

    expect(component.isTicketFormOpen()).toBe(false);
  });

  it('assigns a request through the assignment dialog', async () => {
    const component = await createPage('super_admin');
    flushInit([ticketFixture], [categoryFixture], [managedUserFixture]);
    harness.fixture.detectChanges();

    component.selectTicket(42);
    flushDetailAll();
    harness.fixture.detectChanges();

    component.openAssignDialog(ticketFixture);
    component.assigneeForm.patchValue({ user_id: 9 });
    component.submitAssignment();

    const update = controller.expectOne(req => req.method === 'PUT' && req.url === '/api/v1/tickets/42');
    expect(update.request.body).toEqual({ assigned_to: 9 });
    update.flush({ success: true, message: 'Assignment updated', data: ticketFixture });
    flushAfterChange();
    harness.fixture.detectChanges();

    expect(component.isAssignFormOpen()).toBe(false);
  });

  it('removes an assignment after confirmation', async () => {
    const component = await createPage('super_admin');
    flushInit([ticketFixture], [categoryFixture], [managedUserFixture]);
    harness.fixture.detectChanges();

    component.selectTicket(42);
    flushDetailAll();
    harness.fixture.detectChanges();

    component.requestUnassignTicket(ticketFixture);
    expect(component.confirmRequest()).not.toBeNull();
    component.executeConfirm();

    const update = controller.expectOne(req => req.method === 'PUT' && req.url === '/api/v1/tickets/42');
    expect(update.request.body).toEqual({ assigned_to: null });
    update.flush({ success: true, message: 'Assignment updated', data: { ...ticketFixture, assignee: null } });
    flushAfterChange();
    harness.fixture.detectChanges();

    expect(component.confirmRequest()).toBeNull();
  });

  it('posts a comment and reloads the thread', async () => {
    const component = await createPage('super_admin');
    flushInit([ticketFixture], [categoryFixture], [managedUserFixture]);
    harness.fixture.detectChanges();

    component.selectTicket(42);
    flushDetailAll();
    harness.fixture.detectChanges();

    component.commentForm.patchValue({ comment: 'Replacing the drum today.' });
    component.submitComment();

    const post = controller.expectOne(req => req.method === 'POST' && req.url === '/api/v1/tickets/42/comments');
    expect(post.request.body).toEqual({ comment: 'Replacing the drum today.', is_internal: false });
    post.flush({
      success: true,
      message: 'Comment added',
      data: { ...commentFixture, id: 2, comment: 'Replacing the drum today.' },
    });
    controller.expectOne(req => req.url === '/api/v1/tickets/42/comments').flush(paged([commentFixture], 100));
    harness.fixture.detectChanges();

    expect(component.commentForm.value.comment).toBe('');
  });

  it('posts an internal note for agents', async () => {
    const component = await createPage('super_admin');
    flushInit([ticketFixture], [categoryFixture], [managedUserFixture]);
    harness.fixture.detectChanges();

    component.selectTicket(42);
    flushDetailAll();
    harness.fixture.detectChanges();

    component.commentForm.patchValue({ comment: 'Replace the drum next round.', is_internal: true });
    component.submitComment();

    const post = controller.expectOne(req => req.method === 'POST' && req.url === '/api/v1/tickets/42/comments');
    expect(post.request.body).toEqual({ comment: 'Replace the drum next round.', is_internal: true });
    post.flush({
      success: true,
      message: 'Internal note added',
      data: { ...commentFixture, id: 2, is_internal: true },
    });
    controller.expectOne(req => req.url === '/api/v1/tickets/42/comments').flush(paged([commentFixture], 100));
    harness.fixture.detectChanges();
  });

  it('blocks comment submission without text', async () => {
    const component = await createPage('staff');
    flushInit([ticketFixture], [categoryFixture]);
    harness.fixture.detectChanges();

    component.selectTicket(42);
    flushDetailAll();
    harness.fixture.detectChanges();

    component.commentForm.reset({ comment: '', is_internal: false });
    component.submitComment();

    expect(component.commentErrors()['comment']).toBeTruthy();
  });

  it('renders the categories tab and deletes a category after confirmation', async () => {
    const component = await createPage('super_admin');
    flushInit([], [], [managedUserFixture]);
    harness.fixture.detectChanges();

    component.setTab('categories');
    controller.expectOne(req => req.url === '/api/v1/ticket-categories').flush(paged([categoryFixture], 15));
    harness.fixture.detectChanges();

    const tableText: string = harness.fixture.nativeElement.querySelector('.nx-table')?.textContent ?? '';
    expect(tableText).toContain('Hardware');
    expect(tableText).toContain('HWR');
    expect(tableText).toContain('2');

    component.requestDeleteCategory(categoryFixture);
    expect(component.confirmRequest()).not.toBeNull();
    component.executeConfirm();

    controller.expectOne(req => req.method === 'DELETE' && req.url === '/api/v1/ticket-categories/1').flush({
      success: true,
      message: 'Category deleted',
      data: null,
    });
    controller.expectOne(req => req.url === '/api/v1/ticket-categories' && req.params.get('per_page') === '100').flush(paged([], 100));
    controller.expectOne(req => req.url === '/api/v1/ticket-categories' && req.params.get('per_page') === '15').flush(paged([], 15));
    controller.expectOne(req => req.url === '/api/v1/tickets').flush(paged([], 15));
    harness.fixture.detectChanges();

    expect(component.confirmRequest()).toBeNull();
    expect(component.ticketCategories()).toEqual([]);
  });

  it('renders an access-restricted state when the backend denies the ticket', async () => {
    const component = await createPage('staff');
    flushInit([], []);
    harness.fixture.detectChanges();

    component.selectTicket(42);
    controller.expectOne('/api/v1/tickets/42').flush(
      { success: false, message: 'You do not have access to this ticket.' },
      { status: 403, statusText: 'Forbidden' },
    );
    controller.expectOne(req => req.url === '/api/v1/tickets/42/comments').flush(paged([], 100));
    controller.expectOne(req => req.url === '/api/v1/tickets/42/history').flush(paged([], 100));
    harness.fixture.detectChanges();

    expect(component.detailError()?.accessDenied).toBe(true);
    expect(harness.fixture.nativeElement.textContent).toContain('ACCESS RESTRICTED');
  });

  it('renders a back-to-requests action for a missing request', async () => {
    const component = await createPage('staff');
    flushInit([], []);
    harness.fixture.detectChanges();

    component.selectTicket(99);
    controller.expectOne('/api/v1/tickets/99').flush(
      { success: false, message: 'Request not found.' },
      { status: 404, statusText: 'Not Found' },
    );
    controller.expectOne(req => req.url === '/api/v1/tickets/99/comments').flush(paged([], 100));
    controller.expectOne(req => req.url === '/api/v1/tickets/99/history').flush(paged([], 100));
    harness.fixture.detectChanges();

    expect(harness.fixture.nativeElement.textContent).toContain('UNABLE TO LOAD REQUEST');
    expect(harness.fixture.nativeElement.textContent).toContain('Back to Requests');
    expect(component.detailError()?.accessDenied).toBe(false);
  });
});

const ticketFixture: Ticket = {
  id: 42,
  ticket_number: 'TKT-000042',
  title: 'Printer not working',
  description: 'Office printer jammed again.',
  category: { id: 1, name: 'Hardware', code: 'HWR' },
  requester: {
    id: 5,
    name: 'Lina',
    email: 'lina@nexora.test',
    role: { id: 4, name: 'Staff', slug: 'staff' },
    department: { id: 1, name: 'Operations', code: 'OPS' },
    is_active: true,
    created_at: null,
  },
  assignee: {
    id: 9,
    name: 'Nadira',
    email: 'nadira@nexora.test',
    role: { id: 2, name: 'Technician', slug: 'technician' },
    department: { id: 2, name: 'IT', code: 'IT' },
    is_active: true,
    created_at: null,
  },
  department: { id: 1, name: 'Operations', code: 'OPS' },
  location: { id: 3, name: 'HQ Office', code: 'HQ' },
  priority: 'HIGH',
  status: 'IN_PROGRESS',
  created_at: '2025-02-10T08:00:00Z',
  updated_at: '2025-02-11T09:30:00Z',
};

const categoryFixture: TicketCategory = {
  id: 1,
  name: 'Hardware',
  code: 'HWR',
  description: null,
  tickets_count: 2,
  created_at: null,
  updated_at: null,
};

const commentFixture: TicketComment = {
  id: 1,
  comment: 'Checked the printer, replacing the drum.',
  is_internal: false,
  user: {
    id: 9,
    name: 'Nadira',
    email: 'nadira@nexora.test',
    role: { id: 2, name: 'Technician', slug: 'technician' },
    department: { id: 2, name: 'IT', code: 'IT' },
    is_active: true,
    created_at: null,
  },
  created_at: '2025-02-10T09:00:00Z',
  updated_at: null,
};

const historyFixture: TicketHistory = {
  id: 1,
  action: 'STATUS_CHANGED',
  old_status: 'OPEN',
  new_status: 'IN_PROGRESS',
  notes: null,
  user: {
    id: 9,
    name: 'Nadira',
    email: 'nadira@nexora.test',
    role: { id: 2, name: 'Technician', slug: 'technician' },
    department: { id: 2, name: 'IT', code: 'IT' },
    is_active: true,
    created_at: null,
  },
  created_at: '2025-02-10T08:30:00Z',
};

const managedUserFixture: ManagedUser = {
  id: 9,
  name: 'Nadira',
  email: 'nadira@nexora.test',
  is_active: true,
  role: { id: 2, name: 'Technician', slug: 'technician' },
  department: { id: 2, name: 'IT', code: 'IT' },
  created_at: null,
  updated_at: null,
};