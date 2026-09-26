import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { provideRouter } from '@angular/router';
import { RouterTestingHarness } from '@angular/router/testing';
import { IonicModule } from '@ionic/angular/lazy';

import { AuthService } from '../../core/services/auth.service';
import { StorageService } from '../../core/services/storage.service';
import {
  Asset,
  AssetAssignmentItem,
  AssetCategoryItem,
  LocationRecord,
} from '../../core/services/asset.service';
import { AssetsPage } from './assets.page';

const routes = [{ path: 'assets', component: AssetsPage }];

const assetFixture: Asset = {
  id: 1,
  asset_code: 'NX-LPT-001',
  name: 'Lenovo ThinkPad X1',
  description: 'Primary developer laptop.',
  serial_number: 'PFQSD123456',
  status: 'ACTIVE',
  condition: 'GOOD',
  purchase_date: '2025-01-10',
  purchase_price: 1250.5,
  warranty_expiry: '2028-01-10',
  category: { id: 1, name: 'Laptops', code: 'LPT' },
  location: { id: 2, name: 'HQ Office', code: 'HQ' },
  current_user: { id: 7, name: 'Nadira', email: 'nadira@nexora.test' },
  created_at: '2025-02-01T10:00:00Z',
  updated_at: '2025-02-01T10:00:00Z',
};

const categoryFixture: AssetCategoryItem = {
  id: 1,
  name: 'Laptops',
  code: 'LPT',
  description: null,
  assets_count: 0,
  created_at: null,
  updated_at: null,
};

const locationFixture: LocationRecord = {
  id: 2,
  name: 'HQ Office',
  code: 'HQ',
  description: null,
  address: null,
  is_active: true,
  created_at: null,
  updated_at: null,
};

const activeAssignmentFixture: AssetAssignmentItem = {
  id: 9,
  asset: { id: 1, asset_code: 'NX-LPT-001', name: 'Lenovo ThinkPad X1' },
  user: { id: 7, name: 'Nadira', email: 'nadira@nexora.test' },
  requested_by: null,
  location: null,
  status: 'ACTIVE',
  assigned_at: '2025-02-02T08:00:00Z',
  returned_at: null,
  notes: 'Handover complete.',
  created_at: '2025-02-02T08:00:00Z',
  updated_at: null,
};

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

describe('AssetsPage', () => {
  let controller: HttpTestingController;
  let harness: RouterTestingHarness;

  async function createPage(roleSlug: string): Promise<AssetsPage> {
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
    return harness.navigateByUrl('/assets', AssetsPage);
  }

  function flushLookups(): void {
    controller.expectOne(req => req.url === '/api/v1/asset-categories').flush(paged([categoryFixture], 100));
    controller.expectOne(req => req.url === '/api/v1/locations').flush(paged([locationFixture], 100));
  }

  function flushAssetsList(items: Asset[] = []): void {
    controller.expectOne(req => req.url === '/api/v1/assets').flush(paged(items));
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

  it('renders the asset list rows for an authenticated super admin', async () => {
    const component = await createPage('super_admin');
    flushLookups();
    flushAssetsList([assetFixture]);
    harness.fixture.detectChanges();

    const rows = harness.fixture.nativeElement.querySelectorAll('.nx-asset-row');
    expect(rows.length).toBe(1);
    expect(rows[0].textContent).toContain('NX-LPT-001');
    expect(rows[0].textContent).toContain('Lenovo ThinkPad X1');
    expect(component.assets().length).toBe(1);
  });

  it('renders the empty state when the backend returns no assets', async () => {
    await createPage('super_admin');
    flushLookups();
    flushAssetsList([]);
    harness.fixture.detectChanges();

    const text = harness.fixture.nativeElement.textContent;
    expect(text).toContain('NO ASSETS');
    expect(harness.fixture.nativeElement.querySelectorAll('.nx-asset-row').length).toBe(0);
  });

  it('renders an error state and retries the list', async () => {
    const component = await createPage('super_admin');
    flushLookups();
    controller.expectOne(req => req.url === '/api/v1/assets').flush(
      { success: false, message: 'Server exploded.' },
      { status: 500, statusText: 'Server Error' },
    );
    harness.fixture.detectChanges();

    expect(component.errorState()).not.toBeNull();
    const text = harness.fixture.nativeElement.textContent;
    expect(text).toContain('UNABLE TO LOAD ASSETS');

    const retryButton = Array.from<HTMLButtonElement>(harness.fixture.nativeElement.querySelectorAll('button')).find(
      b => b.textContent?.trim() === 'Retry',
    );
    retryButton?.dispatchEvent(new MouseEvent('click'));
    flushAssetsList([assetFixture]);
    harness.fixture.detectChanges();

    expect(component.errorState()).toBeNull();
    expect(harness.fixture.nativeElement.querySelectorAll('.nx-asset-row').length).toBe(1);
  });

  it('selecting an asset renders detail with QR identity, properties and assignment', async () => {
    const component = await createPage('super_admin');
    flushLookups();
    flushAssetsList([assetFixture]);
    harness.fixture.detectChanges();

    component.selectAsset(assetFixture);

    controller.expectOne('/api/v1/assets/1').flush({
      success: true,
      message: 'ok',
      data: assetFixture,
    });
    controller.expectOne('/api/v1/assets/1/qr').flush({
      success: true,
      message: 'ok',
      data: { asset_id: 1, identifier: 'NX-LPT-001', payload: 'NEXORA:ASSET:NX-LPT-001' },
    });
    controller.expectOne(req => req.url === '/api/v1/asset-assignments').flush(paged([activeAssignmentFixture], 1));
    harness.fixture.detectChanges();

    const detail = harness.fixture.nativeElement.querySelector('.nx-detail');
    const detailText: string = detail?.textContent ?? '';
    expect(component.selectedAssetId()).toBe(1);
    expect(detailText).toContain('Lenovo ThinkPad X1');
    expect(detailText).toContain('NEXORA:ASSET:NX-LPT-001');
    expect(detailText).toContain('Laptops');
    expect(detailText).toContain('Assigned');
    expect(detailText).toContain('Nadira');
    expect(detailText).toContain('Handover complete.');
  });

  it('hides the New Asset button for users without manage_assets', async () => {
    await createPage('staff');
    flushLookups();
    flushAssetsList([assetFixture]);
    harness.fixture.detectChanges();

    const header = harness.fixture.nativeElement.querySelector('.nx-page-header');
    expect(header.textContent).not.toContain('New Asset');
  });

  it('shows the New Asset button for users with manage_assets', async () => {
    await createPage('super_admin');
    flushLookups();
    flushAssetsList([assetFixture]);
    harness.fixture.detectChanges();

    expect(harness.fixture.nativeElement.querySelector('.nx-page-header').textContent).toContain('New Asset');
  });

  it('shows the assignment panel for viewers but only action buttons for managers of assignments', async () => {
    const component = await createPage('manager');
    flushLookups();
    flushAssetsList([assetFixture]);
    harness.fixture.detectChanges();

    component.selectAsset(assetFixture);
    controller.expectOne('/api/v1/assets/1').flush({ success: true, message: 'ok', data: assetFixture });
    controller.expectOne('/api/v1/assets/1/qr').flush({
      success: true,
      message: 'ok',
      data: { asset_id: 1, identifier: 'NX-LPT-001', payload: 'NEXORA:ASSET:NX-LPT-001' },
    });
    controller.expectOne(req => req.url === '/api/v1/asset-assignments').flush(paged([activeAssignmentFixture], 1));
    harness.fixture.detectChanges();

    const detail = harness.fixture.nativeElement.querySelector('.nx-detail');
    expect(detail.textContent).toContain('Assignment');
    const detailButtons = Array.from<HTMLButtonElement>(detail.querySelectorAll('button')).map(b => b.textContent?.trim() ?? '');
    expect(detailButtons.some(t => t === 'Assign')).toBe(false);
    expect(detailButtons.some(t => t === 'Return')).toBe(false);
    expect(component.canViewAssignments()).toBe(true);
    expect(component.hasManageAssignments()).toBe(false);
  });

  it('hides the assignment panel for users who cannot view assignments', async () => {
    await createPage('staff');
    flushLookups();
    flushAssetsList([]);
    harness.fixture.detectChanges();

    expect(harness.fixture.nativeElement.textContent).not.toContain('Assignment');
  });

  it('resolves an asset through the QR lookup form', async () => {
    const component = await createPage('super_admin');
    flushLookups();
    flushAssetsList([assetFixture]);
    harness.fixture.detectChanges();

    component.onLookupInput('NEXORA:ASSET:NX-LPT-001');
    component.lookupQr();

    controller.expectOne(req => req.urlWithParams.includes('/api/v1/assets/qr/NEXORA%3AASSET%3ANX-LPT-001')).flush({
      success: true,
      message: 'ok',
      data: assetFixture,
    });
    controller.expectOne('/api/v1/assets/1').flush({ success: true, message: 'ok', data: assetFixture });
    controller.expectOne('/api/v1/assets/1/qr').flush({
      success: true,
      message: 'ok',
      data: { asset_id: 1, identifier: 'NX-LPT-001', payload: 'NEXORA:ASSET:NX-LPT-001' },
    });
    controller.expectOne(req => req.url === '/api/v1/asset-assignments').flush(paged([activeAssignmentFixture], 1));
    harness.fixture.detectChanges();

    expect(component.selectedAssetId()).toBe(1);
    expect(harness.fixture.nativeElement.querySelector('.nx-detail').textContent).toContain('Lenovo ThinkPad X1');
  });
});