import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { provideRouter } from '@angular/router';
import { RouterTestingHarness } from '@angular/router/testing';
import { IonicModule } from '@ionic/angular/lazy';

import { AuthService } from '../../core/services/auth.service';
import { StorageService } from '../../core/services/storage.service';
import type {
  MaintenanceRequest,
  MaintenanceRecord,
  MaintenancePart,
} from '../../core/services/maintenance.service';
import type { Asset } from '../../core/services/asset.service';
import { MaintenancePage } from './maintenance.page';

const routes = [{ path: 'maintenance', component: MaintenancePage }];

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

function paged<T>(
  items: T[],
  perPage = 15,
  total = items.length,
): { success: true; message: string; data: { items: T[]; pagination: { current_page: number; per_page: number; total: number; last_page: number } } } {
  return {
    success: true,
    message: 'ok',
    data: {
      items,
      pagination: { current_page: 1, per_page: perPage, total, last_page: 1 },
    },
  };
}

describe('MaintenancePage', () => {
  let controller: HttpTestingController;
  let harness: RouterTestingHarness;

  async function createPage(roleSlug: string): Promise<MaintenancePage> {
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
    return harness.navigateByUrl('/maintenance', MaintenancePage);
  }

  function flushInit(options: {
    requests?: unknown[];
    recordsTotal?: number;
    users?: unknown[];
    assets?: unknown[];
    locations?: unknown[];
  } = {}): void {
    const requests = options.requests ?? [];
    controller.expectOne(req => req.url.startsWith('/api/v1/maintenance-requests')).flush(paged(requests, 15));
    controller.expectOne(req => req.url.startsWith('/api/v1/maintenance-records')).flush(paged([], 1, options.recordsTotal ?? 0));
    controller.match(req => req.url.startsWith('/api/v1/assets')).forEach(req => req.flush(paged(options.assets ?? [assetFixture], 100)));
    controller.match(req => req.url.startsWith('/api/v1/users')).forEach(req => req.flush(paged(options.users ?? [managedUserFixture], 100)));
    controller.match(req => req.url.startsWith('/api/v1/locations')).forEach(req => req.flush(paged(options.locations ?? [locationFixture], 100)));
  }

  function flushDetail(id = 42, data: unknown = requestFixture): void {
    controller.expectOne(req => req.url.startsWith(`/api/v1/maintenance-requests/${id}`)).flush({
      success: true,
      message: 'ok',
      data,
    });
  }

  function flushRequestsRefresh(items: unknown[]): void {
    controller.expectOne(req => req.url.startsWith('/api/v1/maintenance-requests')).flush(paged(items, 15));
  }

  function flushItems(): void {
    controller.match(req => req.url.startsWith('/api/v1/items')).forEach(req => req.flush(paged([itemFixture], 100)));
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

  it('renders the header, summary strip and a request row for a manager', async () => {
    const component = await createPage('super_admin');
    flushInit({ requests: [requestFixture], recordsTotal: 3 });
    harness.fixture.detectChanges();

    const text = harness.fixture.nativeElement.textContent;
    expect(text).toContain('Maintenance');
    expect(harness.fixture.nativeElement.querySelectorAll('.nx-summary-item').length).toBe(2);
    const rows = harness.fixture.nativeElement.querySelectorAll('.nx-item-row');
    expect(rows.length).toBe(1);
    expect(rows[0].textContent).toContain('Servo motor vibration');
    expect(component.requests().length).toBe(1);
    expect(component.requestsTotal()).toBe(1);
    expect(component.recordsTotal()).toBe(3);
    expect(component.canCreateRequest()).toBe(true);
    expect(component.hasManage()).toBe(true);
  });

  it('renders the empty state when the backend returns no requests', async () => {
    await createPage('super_admin');
    flushInit();
    harness.fixture.detectChanges();

    const text = harness.fixture.nativeElement.textContent;
    expect(text).toContain('NO MAINTENANCE REQUESTS');
    expect(harness.fixture.nativeElement.querySelectorAll('.nx-item-row').length).toBe(0);
  });

  it('renders an error state and retries the list', async () => {
    const component = await createPage('super_admin');
    controller.expectOne(req => req.url.startsWith('/api/v1/maintenance-records')).flush(paged([], 1));
    controller.match(req => req.url.startsWith('/api/v1/assets')).forEach(req => req.flush(paged([], 100)));
    controller.match(req => req.url.startsWith('/api/v1/users')).forEach(req => req.flush(paged([], 100)));
    controller.match(req => req.url.startsWith('/api/v1/locations')).forEach(req => req.flush(paged([], 100)));
    controller.expectOne(req => req.url.startsWith('/api/v1/maintenance-requests')).flush(
      { success: false, message: 'Server exploded.' },
      { status: 500, statusText: 'Server Error' },
    );
    harness.fixture.detectChanges();

    expect(component.requestsError()).not.toBeNull();
    expect(harness.fixture.nativeElement.textContent).toContain('UNABLE TO LOAD REQUESTS');

    const retryButton = Array.from<HTMLButtonElement>(harness.fixture.nativeElement.querySelectorAll('button')).find(
      b => b.textContent?.trim() === 'Retry',
    );
    retryButton?.dispatchEvent(new MouseEvent('click'));
    flushRequestsRefresh([requestFixture]);
    harness.fixture.detectChanges();

    expect(component.requestsError()).toBeNull();
    expect(harness.fixture.nativeElement.querySelectorAll('.nx-item-row').length).toBe(1);
  });

  it('keeps New Request visible for staff but hides manage actions', async () => {
    const component = await createPage('staff');
    flushInit({ requests: [requestFixture] });
    harness.fixture.detectChanges();

    expect(harness.fixture.nativeElement.querySelector('.nx-page-header').textContent).toContain('New Request');
    expect(component.hasManage()).toBe(false);

    component.selectRequest(42);
    flushDetail();
    harness.fixture.detectChanges();

    const detailText: string = harness.fixture.nativeElement.querySelector('.nx-detail')?.textContent ?? '';
    expect(detailText).toContain('Servo motor vibration');
    expect(detailText).not.toContain('Approve request');
    expect(detailText).not.toContain('Start Work');
    expect(detailText).not.toContain('Complete request');
    expect(detailText).not.toContain('Cancel request');
    expect(detailText).not.toContain('Reassign');
  });

  it('selecting a request renders its detail overview and workflow controls', async () => {
    const component = await createPage('super_admin');
    flushInit({ requests: [requestFixture] });
    harness.fixture.detectChanges();

    component.selectRequest(42);
    flushDetail();
    harness.fixture.detectChanges();

    expect(component.selectedRequestId()).toBe(42);
    const detailText: string = harness.fixture.nativeElement.querySelector('.nx-detail')?.textContent ?? '';
    expect(detailText).toContain('AST-00342');
    expect(detailText).toContain('Requested');
    expect(detailText).toContain('High');
    expect(detailText).toContain('Lina');
    const actionsText: string = harness.fixture.nativeElement.querySelector('.nx-detail-actions')?.textContent ?? '';
    expect(actionsText).toContain('Approve');
    expect(actionsText).toContain('Cancel');
    expect(detailText).toContain('NO WORK RECORDS');
  });

  it('approves a pending request after confirmation', async () => {
    const component = await createPage('super_admin');
    flushInit({ requests: [requestFixture] });
    harness.fixture.detectChanges();

    component.selectRequest(42);
    flushDetail();
    harness.fixture.detectChanges();

    component.requestApprove(requestFixture);
    expect(component.confirmRequest()).not.toBeNull();
    component.executeConfirm();

    const update = controller.expectOne(req => req.method === 'PUT' && req.url.startsWith('/api/v1/maintenance-requests/42'));
    expect(update.request.body).toEqual({ status: 'APPROVED' });
    update.flush({ success: true, message: 'Request updated', data: approvedFixture });
    flushDetail(42, approvedFixture);
    flushRequestsRefresh([approvedFixture]);
    harness.fixture.detectChanges();

    expect(component.confirmRequest()).toBeNull();
    expect(component.detailRequest()?.status).toBe('APPROVED');
  });

  it('starts work by creating a work record — never a direct status push', async () => {
    const component = await createPage('super_admin');
    flushInit({ requests: [approvedFixture] });
    harness.fixture.detectChanges();

    component.selectRequest(42);
    flushDetail(42, approvedFixture);
    harness.fixture.detectChanges();

    component.requestStartWork(approvedFixture);
    expect(component.isStartOpen()).toBe(true);
    expect(component.startTarget()?.id).toBe(42);

    component.startWorkForm.patchValue({ description: 'Balancing the shaft' });
    component.submitStartWork();

    const post = controller.expectOne(req => req.method === 'POST' && req.url.startsWith('/api/v1/maintenance-records'));
    expect(post.request.body).not.toHaveProperty('status');
    expect(post.request.body).toEqual({
      maintenance_request_id: 42,
      description: 'Balancing the shaft',
      technician_id: null,
    });
    post.flush({ success: true, message: 'Work started', data: recordFixture });
    flushDetail(42, inProgressFixture);
    flushRequestsRefresh([inProgressFixture]);
    harness.fixture.detectChanges();

    expect(component.isStartOpen()).toBe(false);
    expect(component.detailRequest()?.status).toBe('IN_PROGRESS');
  });

  it('passes an explicit technician when starting work', async () => {
    const component = await createPage('super_admin');
    flushInit({ requests: [approvedFixture] });
    harness.fixture.detectChanges();

    component.selectRequest(42);
    flushDetail(42, approvedFixture);
    harness.fixture.detectChanges();

    component.requestStartWork(approvedFixture);
    component.startWorkForm.patchValue({ description: 'Replacing the belt', technician_id: 9 });
    component.submitStartWork();

    const post = controller.expectOne(req => req.method === 'POST' && req.url.startsWith('/api/v1/maintenance-records'));
    expect(post.request.body).toEqual({
      maintenance_request_id: 42,
      description: 'Replacing the belt',
      technician_id: 9,
    });
    post.flush({ success: true, message: 'Work started', data: recordFixture });
    flushDetail(42, inProgressFixture);
    flushRequestsRefresh([inProgressFixture]);
    harness.fixture.detectChanges();
  });

  it('completes an in-progress request after confirmation', async () => {
    const component = await createPage('super_admin');
    flushInit({ requests: [inProgressFixture] });
    harness.fixture.detectChanges();

    component.selectRequest(42);
    flushDetail(42, inProgressFixture);
    harness.fixture.detectChanges();

    component.requestComplete(inProgressFixture);
    expect(component.confirmRequest()).not.toBeNull();
    component.executeConfirm();

    const update = controller.expectOne(req => req.method === 'PUT' && req.url.startsWith('/api/v1/maintenance-requests/42'));
    expect(update.request.body).toEqual({ status: 'COMPLETED' });
    update.flush({ success: true, message: 'Request updated', data: completedFixture });
    flushDetail(42, completedFixture);
    flushRequestsRefresh([completedFixture]);
    harness.fixture.detectChanges();

    expect(component.confirmRequest()).toBeNull();
    expect(component.detailRequest()?.status).toBe('COMPLETED');
  });

  it('cancels a pending request after confirmation', async () => {
    const component = await createPage('super_admin');
    flushInit({ requests: [requestFixture] });
    harness.fixture.detectChanges();

    component.selectRequest(42);
    flushDetail();
    harness.fixture.detectChanges();

    component.requestCancel(requestFixture);
    component.executeConfirm();

    const update = controller.expectOne(req => req.method === 'PUT' && req.url.startsWith('/api/v1/maintenance-requests/42'));
    expect(update.request.body).toEqual({ status: 'CANCELLED' });
    update.flush({ success: true, message: 'Request updated', data: cancelledFixture });
    flushDetail(42, cancelledFixture);
    flushRequestsRefresh([cancelledFixture]);
    harness.fixture.detectChanges();

    expect(component.detailRequest()?.status).toBe('CANCELLED');
  });

  it('assigns a request through the assignment dialog', async () => {
    const component = await createPage('super_admin');
    flushInit({ requests: [requestFixture] });
    harness.fixture.detectChanges();

    component.selectRequest(42);
    flushDetail();
    harness.fixture.detectChanges();

    component.openAssignDialog(requestFixture);
    expect(component.isAssignOpen()).toBe(true);
    component.assigneeForm.patchValue({ user_id: 9 });
    component.submitAssignment();

    const update = controller.expectOne(req => req.method === 'PUT' && req.url.startsWith('/api/v1/maintenance-requests/42'));
    expect(update.request.body).toEqual({ assigned_to: 9 });
    update.flush({ success: true, message: 'Assignment updated', data: assignedFixture });
    flushDetail(42, assignedFixture);
    flushRequestsRefresh([assignedFixture]);
    harness.fixture.detectChanges();

    expect(component.isAssignOpen()).toBe(false);
    expect(component.assignTarget()).toBeNull();
  });

  it('edits a work record with work fields only (no request/asset spoofing)', async () => {
    const component = await createPage('super_admin');
    flushInit({ requests: [inProgressFixture] });
    harness.fixture.detectChanges();

    component.selectRequest(42);
    flushDetail(42, inProgressFixture);
    harness.fixture.detectChanges();

    component.openRecordEdit(recordFixture, inProgressFixture);
    expect(component.isRecordEditOpen()).toBe(true);

    component.recordEditForm.patchValue({
      description: 'Rebalanced.',
      result: 'Balanced and tightened.',
      cost: '135.00',
      started_at: null,
      completed_at: null,
      technician_id: null,
    });
    component.submitRecordEdit();

    const update = controller.expectOne(req => req.method === 'PUT' && req.url.startsWith('/api/v1/maintenance-records/3'));
    expect(update.request.body).not.toHaveProperty('maintenance_request_id');
    expect(update.request.body).not.toHaveProperty('asset_id');
    expect(update.request.body).toEqual({
      description: 'Rebalanced.',
      started_at: null,
      completed_at: null,
      result: 'Balanced and tightened.',
      cost: 135,
      technician_id: null,
    });
    update.flush({ success: true, message: 'Record updated', data: recordFixture });
    flushDetail(42, inProgressFixture);
    flushRequestsRefresh([inProgressFixture]);
    harness.fixture.detectChanges();

    expect(component.isRecordEditOpen()).toBe(false);
  });

  it('records a part through the parts journal without touching any stock endpoint', async () => {
    const component = await createPage('super_admin');
    flushInit({ requests: [inProgressFixture] });
    harness.fixture.detectChanges();

    component.selectRequest(42);
    flushDetail(42, inProgressFixture);
    harness.fixture.detectChanges();

    component.openPartForm(recordFixture);
    flushItems();
    harness.fixture.detectChanges();
    expect(component.isPartOpen()).toBe(true);
    expect(component.partTargetRecord()?.id).toBe(3);

    component.partForm.patchValue({ item_id: 55, quantity: 2 });
    component.submitPartForm();

    const post = controller.expectOne(req => req.method === 'POST' && req.url.startsWith('/api/v1/maintenance-records/3/parts'));
    expect(post.request.body).toEqual({ item_id: 55, quantity: 2 });
    expect(controller.match(req => /stock|movement/i.test(req.url))).toEqual([]);
    post.flush({ success: true, message: 'Part recorded', data: partFixture });
    flushDetail(42, inProgressFixture);
    flushRequestsRefresh([inProgressFixture]);
    harness.fixture.detectChanges();

    expect(component.isPartOpen()).toBe(false);
  });

  it('creates a request and selects the new row', async () => {
    const component = await createPage('super_admin');
    flushInit({ requests: [] });
    harness.fixture.detectChanges();

    component.openCreateForm();
    controller.match(req => req.url.startsWith('/api/v1/assets')).forEach(req => req.flush(paged([assetFixture], 100)));
    harness.fixture.detectChanges();
    expect(component.isCreateOpen()).toBe(true);

    component.requestForm.patchValue({
      asset_id: 12,
      title: 'Laptop dead',
      description: 'Will not power on.',
      priority: 'URGENT',
    });
    component.submitCreateForm();

    const create = controller.expectOne(req => req.method === 'POST' && req.url.startsWith('/api/v1/maintenance-requests'));
    expect(create.request.body).not.toHaveProperty('requested_by');
    expect(create.request.body).not.toHaveProperty('status');
    expect(create.request.body).toEqual({
      asset_id: 12,
      title: 'Laptop dead',
      description: 'Will not power on.',
      priority: 'URGENT',
    });
    create.flush({
      success: true,
      message: 'Request created',
      data: { ...requestFixture, id: 44, title: 'Laptop dead', priority: 'URGENT' },
    });
    flushDetail(44, { ...requestFixture, id: 44, title: 'Laptop dead', priority: 'URGENT' });
    flushRequestsRefresh([requestFixture]);
    harness.fixture.detectChanges();

    expect(component.isCreateOpen()).toBe(false);
    expect(component.selectedRequestId()).toBe(44);
  });

  it('blocks request creation with client-side validation errors', async () => {
    const component = await createPage('super_admin');
    flushInit();
    harness.fixture.detectChanges();

    component.openCreateForm();
    controller.match(req => req.url.startsWith('/api/v1/assets')).forEach(req => req.flush(paged([assetFixture], 100)));
    harness.fixture.detectChanges();

    component.submitCreateForm();

    expect(component.isCreateOpen()).toBe(true);
    expect(component.createErrors()['title']).toBeTruthy();
    expect(component.createErrors()['description']).toBeTruthy();
    expect(component.createErrors()['asset_id']).toBeTruthy();
  });

  it('filters ineligible assets out of the create picker', async () => {
    const component = await createPage('super_admin');
    flushInit({ requests: [], assets: [assetFixture, retiredAsset] });
    harness.fixture.detectChanges();

    // The filter dropdown loads every asset (including ineligible ones).
    expect(component.assetOptions().length).toBe(2);

    component.openCreateForm();
    controller.match(req => req.url.startsWith('/api/v1/assets')).forEach(req => req.flush(paged([assetFixture, retiredAsset], 100)));
    harness.fixture.detectChanges();

    expect(component.assetOptions().some(asset => asset.asset_code === 'AST-00342')).toBe(true);
    expect(component.assetOptions().some(asset => asset.asset_code === 'AST-00099')).toBe(false);
  });

  it('rejects invalid part quantities client-side', async () => {
    const component = await createPage('super_admin');
    flushInit({ requests: [inProgressFixture] });
    harness.fixture.detectChanges();

    component.selectRequest(42);
    flushDetail(42, inProgressFixture);
    harness.fixture.detectChanges();

    component.openPartForm(recordFixture);
    flushItems();
    harness.fixture.detectChanges();

    component.partForm.patchValue({ item_id: 55, quantity: 0 });
    component.submitPartForm();

    expect(component.partErrors()['quantity']).toBeTruthy();
    expect(component.isPartOpen()).toBe(true);
  });

  it('renders an access-restricted state when the backend denies the request', async () => {
    const component = await createPage('staff');
    flushInit({ requests: [requestFixture] });
    harness.fixture.detectChanges();

    component.selectRequest(42);
    controller.expectOne(req => req.url.startsWith('/api/v1/maintenance-requests/42')).flush(
      { success: false, message: 'You do not have access to this maintenance request.' },
      { status: 403, statusText: 'Forbidden' },
    );
    harness.fixture.detectChanges();

    expect(component.detailError()?.accessDenied).toBe(true);
    expect(harness.fixture.nativeElement.textContent).toContain('ACCESS RESTRICTED');
  });

  it('renders a back-to-requests action when the request is missing', async () => {
    const component = await createPage('staff');
    flushInit({ requests: [] });
    harness.fixture.detectChanges();

    component.selectRequest(99);
    controller.expectOne(req => req.url.startsWith('/api/v1/maintenance-requests/99')).flush(
      { success: false, message: 'Request not found.' },
      { status: 404, statusText: 'Not Found' },
    );
    harness.fixture.detectChanges();

    expect(harness.fixture.nativeElement.textContent).toContain('UNABLE TO LOAD REQUEST');
    expect(harness.fixture.nativeElement.textContent).toContain('Back to Requests');
    expect(component.detailError()?.accessDenied).toBe(false);
  });
});

// ─── Fixtures ────────────────────────────────────────────────────────────

const assetFixture: Asset = {
  id: 12,
  asset_code: 'AST-00342',
  name: 'Exhaust Fan',
  description: null,
  serial_number: null,
  status: 'ACTIVE',
  condition: 'GOOD',
  purchase_date: null,
  purchase_price: null,
  warranty_expiry: null,
  category: null,
  location: null,
  current_user: null,
  created_at: null,
  updated_at: null,
};

const retiredAsset: Asset = {
  ...assetFixture,
  id: 13,
  asset_code: 'AST-00099',
  name: 'Retired Conveyor',
  status: 'RETIRED',
};

const requesterFixture = {
  id: 5,
  name: 'Lina',
  email: 'lina@nexora.test',
  role: { id: 4, name: 'Staff', slug: 'staff' },
  department: { id: 1, name: 'Operations', code: 'OPS' },
  is_active: true,
  created_at: null,
};

const technicianFixture = {
  id: 9,
  name: 'Nadira',
  email: 'nadira@nexora.test',
  role: { id: 2, name: 'Technician', slug: 'technician' },
  department: { id: 2, name: 'IT', code: 'IT' },
  is_active: true,
  created_at: null,
};

const partFixture: MaintenancePart = {
  id: 1,
  quantity: 2,
  item: { id: 55, sku: 'BRG-6205', name: '6205 Ball Bearing', unit: 'pcs' },
  created_at: '2025-03-02T10:00:00Z',
};

const recordFixture: MaintenanceRecord = {
  id: 3,
  description: 'Balanced the shaft',
  result: null,
  cost: null,
  started_at: '2025-03-02T09:00:00Z',
  completed_at: null,
  request: { id: 42, title: 'Servo motor vibration', status: 'IN_PROGRESS' },
  asset: { id: 12, asset_code: 'AST-00342', name: 'Exhaust Fan', status: 'ACTIVE' },
  technician: technicianFixture,
  parts: [partFixture],
  parts_count: 1,
  created_at: '2025-03-02T09:00:00Z',
  updated_at: '2025-03-02T09:00:00Z',
};

const requestFixture: MaintenanceRequest = {
  id: 42,
  title: 'Servo motor vibration',
  description: 'Excessive vibration on line 2.',
  priority: 'HIGH',
  status: 'REQUESTED',
  requested_at: '2025-03-01T08:00:00Z',
  approved_at: null,
  completed_at: null,
  asset: { id: 12, asset_code: 'AST-00342', name: 'Exhaust Fan', status: 'ACTIVE' },
  requester: requesterFixture,
  assignee: null,
  records: [],
  created_at: '2025-03-01T08:00:00Z',
  updated_at: '2025-03-01T08:00:00Z',
};

const approvedFixture: MaintenanceRequest = {
  ...requestFixture,
  status: 'APPROVED',
  approved_at: '2025-03-01T09:00:00Z',
  assignee: technicianFixture,
  records: [],
};

const inProgressFixture: MaintenanceRequest = {
  ...requestFixture,
  status: 'IN_PROGRESS',
  approved_at: '2025-03-01T09:00:00Z',
  assignee: technicianFixture,
  records: [recordFixture],
};

const completedFixture: MaintenanceRequest = {
  ...requestFixture,
  status: 'COMPLETED',
  approved_at: '2025-03-01T09:00:00Z',
  completed_at: '2025-03-02T12:30:00Z',
  assignee: technicianFixture,
  records: [recordFixture],
};

const cancelledFixture: MaintenanceRequest = {
  ...requestFixture,
  status: 'CANCELLED',
  records: [],
};

const assignedFixture: MaintenanceRequest = {
  ...requestFixture,
  assignee: technicianFixture,
  records: [],
};

const locationFixture = {
  id: 3,
  name: 'HQ Office',
  code: 'HQ',
  description: null,
  address: null,
  is_active: true,
  created_at: null,
  updated_at: null,
};

const managedUserFixture = {
  id: 9,
  name: 'Nadira',
  email: 'nadira@nexora.test',
  is_active: true,
  role: { id: 2, name: 'Technician', slug: 'technician' },
  department: { id: 2, name: 'IT', code: 'IT' },
  created_at: null,
  updated_at: null,
};

const itemFixture = {
  id: 55,
  sku: 'BRG-6205',
  name: '6205 Ball Bearing',
  unit: 'pcs',
};