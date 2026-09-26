import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { Router } from '@angular/router';
import { IonIcon } from '@ionic/angular';

import { AuthService } from '../../core/services/auth.service';
import { ThemeService } from '../../core/services/theme.service';
import { AuthorizationService } from '../../core/services/authorization.service';
import {
  CommandCenterActivityItem,
  CommandCenterErrorInfo,
  CommandCenterPayload,
  CommandCenterQueue,
  CommandCenterQueueItem,
  CommandCenterQueueKey,
  CommandCenterService,
  mapCommandCenterError,
} from '../../core/services/command-center.service';

import { NxButtonComponent } from '../../shared/components/nx-button/nx-button.component';
import { NxPanelComponent } from '../../shared/components/nx-panel/nx-panel.component';
import { NxEmptyStateComponent } from '../../shared/components/nx-empty-state/nx-empty-state.component';
import { NxErrorStateComponent } from '../../shared/components/nx-error-state/nx-error-state.component';
import { NxLoadingStateComponent } from '../../shared/components/nx-loading-state/nx-loading-state.component';

/**
 * One metric the API returned, with the label the contract gives it.
 *
 * `label` is the only thing this workspace adds to a number. There is no
 * delta, trend, percentage, health state, or severity — a count is displayed as
 * a count, and the label says what was counted.
 */
interface SnapshotMetric {
  label: string;
  value: number;
}

/** A domain group in the snapshot, present only when the caller may read it. */
interface SnapshotGroup {
  key: string;
  title: string;
  metrics: SnapshotMetric[];
}

/**
 * A queue row prepared for display. A `null` target is not a missing feature:
 * it means the API's id does not address anything a workspace can select, and
 * the row is therefore read-only.
 */
interface QueueRow {
  item: CommandCenterQueueItem;
  /** Router commands, or null when the row is a read-only reference. */
  target: string[] | null;
  queryParams: Record<string, string> | null;
}

/**
 * An operational queue, resolved for display: the raw queue plus its rows with
 * navigation already decided, so the template never computes a destination.
 */
interface QueueGroup {
  key: CommandCenterQueueKey;
  title: string;
  queue: CommandCenterQueue;
  rows: QueueRow[];
}

interface QuickAction {
  label: string;
  icon: string;
  route?: string;
  permission?: string;
  action?: string;
}

/** Request state for the single snapshot call. */
interface SnapshotState {
  data: CommandCenterPayload | null;
  loading: boolean;
  refreshing: boolean;
  error: CommandCenterErrorInfo | null;
}

/**
 * Queue presentation order, matching the order the backend documents its
 * queues in. Only the keys actually present in the payload are rendered.
 */
const QUEUE_PRESENTATION: { key: CommandCenterQueueKey; title: string }[] = [
  { key: 'unassigned_tickets', title: 'Unassigned requests' },
  { key: 'unassigned_maintenance_requests', title: 'Unassigned maintenance' },
  { key: 'pending_asset_assignments', title: 'Pending asset handovers' },
];

/**
 * Human-readable labels for the source-native action vocabularies the four
 * activity sources use. This is a *display* mapping only: the raw `action` value
 * is kept in the DOM as `data-action` and in the row key, so nothing is renamed
 * in the data and an action this table does not know still renders as itself.
 */
const ACTIVITY_ACTION_LABELS: Record<string, string> = {
  ASSIGNED: 'Asset assigned',
  RETURNED: 'Asset returned',
  STOCK_IN: 'Stock received',
  STOCK_OUT: 'Stock issued',
  STATUS_CHANGED: 'Status changed',
  WORK_STARTED: 'Maintenance started',
  WORK_COMPLETED: 'Maintenance completed',
};

/** One icon per activity source, so the type is legible without colour. */
const ACTIVITY_TYPE_ICONS: Record<string, string> = {
  asset: 'cube-outline',
  stock_movement: 'swap-horizontal-outline',
  ticket: 'document-text-outline',
  maintenance: 'wrench-outline',
};

/**
 * HomePage — the Command Center / Operational Intelligence workspace.
 *
 * This is the operational cockpit: current state, what is waiting for a person,
 * and what just happened. It is deliberately **not** Reports (phase 18) and
 * deliberately **not** a second dashboard API.
 *
 * Rules this page holds itself to, because an operational number is a fact:
 *
 *  - **one API call.** `GET /command-center` is the only request. The page
 *    never calls the domain list endpoints to rebuild a figure the snapshot
 *    already returned, and never calls Reports.
 *  - **nothing is invented.** No total, rate, ratio, percentage, delta, trend,
 *    score, ranking, or "needs attention" label is computed. A metric the API
 *    omitted is not rendered as zero — the group disappears.
 *  - **a queue row is a link only when the API addressed a real record.** A
 *    ticket or maintenance-request row carries that record's primary key, so it
 *    navigates to the existing workspace selection. An asset-assignment row
 *    carries the *assignment* id, and activity carries an *event row* id —
 *    neither addresses anything a workspace can select, so those rows are
 *    rendered read-only rather than guessing a destination.
 *  - **read-only, manually refreshed.** No polling, no websocket, no auto-
 *    refresh timer. A refresh keeps the current snapshot on screen.
 */
@Component({
  selector: 'app-home',
  standalone: true,
  imports: [
    CommonModule,
    IonIcon,
    NxButtonComponent,
    NxPanelComponent,
    NxEmptyStateComponent,
    NxErrorStateComponent,
    NxLoadingStateComponent,
  ],
  templateUrl: 'home.page.html',
  styleUrls: ['home.page.scss'],
})
export class HomePage implements OnInit {
  readonly auth = inject(AuthService);
  readonly theme = inject(ThemeService);
  private readonly authorization = inject(AuthorizationService);
  private readonly commandCenter = inject(CommandCenterService);
  private readonly router = inject(Router);

  readonly isDark = this.theme.isDark;

  /**
   * Whether the Command Center is available to this caller.
   *
   * The route is left unguarded on purpose: `/home` is the shell's landing page
   * for every authenticated role, and a redirect to `/unauthorized` would bounce
   * a user straight back here. Instead the workspace renders the Access
   * Restricted state and makes no request at all, which keeps the rest of the
   * shell and its navigation fully usable.
   */
  readonly canView = computed(() => this.authorization.hasPermission('view_dashboard'));

  readonly state = signal<SnapshotState>({
    data: null,
    loading: false,
    refreshing: false,
    error: null,
  });

  /** Guards against a second request while one is in flight. */
  private inFlight = false;

  readonly userName = computed(() => this.auth.user()?.name ?? '');

  readonly greeting = computed(() => {
    const hour = new Date().getHours();
    if (hour < 12) return 'Good morning';
    if (hour < 17) return 'Good afternoon';
    return 'Good evening';
  });

  /** True while the first snapshot is being fetched. */
  readonly loading = computed(() => this.state().loading);

  /** True while a refresh runs over an already-rendered snapshot. */
  readonly refreshing = computed(() => this.state().refreshing);

  /** Any request in flight: the Refresh control is disabled while this is true. */
  readonly busy = computed(() => this.state().loading || this.state().refreshing);

  readonly data = computed(() => this.state().data);

  readonly error = computed(() => this.state().error);

  /** True when a payload is on screen, so a refresh never blanks the page. */
  readonly hasSnapshot = computed(() => this.state().data !== null);

  /**
   * Current state, one group per domain the API returned.
   *
   * Field labels mirror the contract's own vocabulary, so "active" and
   * "unassigned" always mean exactly what the API says they mean.
   */
  readonly snapshotGroups = computed<SnapshotGroup[]>(() => {
    const snapshot = this.state().data?.snapshot;
    if (!snapshot) {
      return [];
    }

    const groups: SnapshotGroup[] = [];

    if (snapshot.assets) {
      groups.push({
        key: 'assets',
        title: 'Assets',
        metrics: [
          { label: 'Total', value: snapshot.assets.total },
          { label: 'Active', value: snapshot.assets.active },
          { label: 'In maintenance', value: snapshot.assets.in_maintenance },
          { label: 'Unassigned', value: snapshot.assets.unassigned },
        ],
      });
    }

    if (snapshot.inventory) {
      groups.push({
        key: 'inventory',
        title: 'Inventory',
        metrics: [
          { label: 'Items', value: snapshot.inventory.item_count },
          { label: 'Warehouses', value: snapshot.inventory.warehouse_count },
          { label: 'Stock quantity', value: snapshot.inventory.stock_quantity },
        ],
      });
    }

    if (snapshot.tickets) {
      groups.push({
        key: 'tickets',
        title: 'Requests',
        metrics: [
          { label: 'Total', value: snapshot.tickets.total },
          { label: 'Active', value: snapshot.tickets.active },
          { label: 'Unassigned', value: snapshot.tickets.unassigned },
        ],
      });
    }

    if (snapshot.maintenance) {
      groups.push({
        key: 'maintenance',
        title: 'Maintenance',
        metrics: [
          { label: 'Total', value: snapshot.maintenance.total },
          { label: 'Active', value: snapshot.maintenance.active },
          { label: 'Unassigned', value: snapshot.maintenance.unassigned },
          { label: 'Awaiting approval', value: snapshot.maintenance.awaiting_approval },
        ],
      });
    }

    // The caller's own inbox: the one notification fact the API returns. The
    // shell's bell stays the place notifications are read and marked.
    groups.push({
      key: 'notifications',
      title: 'Notifications',
      metrics: [{ label: 'Unread', value: snapshot.notifications.unread_count }],
    });

    return groups;
  });

  /**
   * Operational queues, in the documented order, only those returned.
   *
   * A queue the caller may not read is absent from the payload, and so is absent
   * here: it is never rendered as an empty queue, because "0 unassigned tickets"
   * and "you cannot see unassigned tickets" are different facts.
   */
  readonly queueGroups = computed<QueueGroup[]>(() => {
    const queues = this.state().data?.queues;
    if (!queues) {
      return [];
    }

    return QUEUE_PRESENTATION.flatMap(presentation => {
      const queue = queues[presentation.key];
      if (!queue) {
        return [];
      }

      return [
        {
          key: presentation.key,
          title: presentation.title,
          queue,
          rows: queue.items.map(item => this.resolveRow(item)),
        },
      ];
    });
  });

  /**
   * Recent activity, exactly as the API ordered it. No client-side re-sorting:
   * the backend already applied its newest-first ordering with a deterministic
   * tiebreak, and re-sorting here could only disagree with it.
   */
  readonly activity = computed(() => this.state().data?.recent_activity.items ?? []);

  /**
   * A command-center with nothing in it: no counts anywhere, no waiting rows,
   * and no events. Distinct from a failure, and distinct from a caller who can
   * simply not see these sections.
   */
  readonly isEmpty = computed(() => {
    const payload = this.state().data;
    if (!payload) {
      return false;
    }

    const hasCounts = this.snapshotGroups().some(group =>
      group.metrics.some(metric => metric.value > 0),
    );
    const hasQueues = this.queueGroups().some(group => group.queue.count > 0);
    const hasActivity = this.activity().length > 0;

    return !hasCounts && !hasQueues && !hasActivity;
  });

  /**
   * Where to go instead, for a caller who may not open the Command Center.
   *
   * The restricted state must not dead-end: the shared `UnauthorizedComponent`
   * only offers "Return to Command", which on this page would navigate to the
   * page the reader is already on. These links go to workspaces the caller
   * demonstrably has access to, so being denied here costs nothing else.
   */
  readonly escapeRoutes = computed(() =>
    [
      { label: 'Requests', route: '/requests', permission: 'view_tickets' },
      { label: 'Inventory', route: '/inventory', permission: 'view_inventory' },
      { label: 'Assets', route: '/assets', permission: 'view_assets' },
    ].filter(link => this.authorization.hasPermission(link.permission)),
  );

  /**
   * One atomic status message for assistive technology, describing the state in
   * words rather than announcing a bare number. It is the only live region on
   * the page, so concurrent updates never compete with each other.
   */
  readonly statusMessage = computed(() => {
    if (this.state().loading) {
      return 'Loading the current operational state.';
    }

    if (this.state().refreshing) {
      return 'Refreshing the current operational state.';
    }

    if (this.state().error) {
      return 'The Command Center could not be loaded.';
    }

    const generatedAt = this.state().data?.generated_at;
    return generatedAt
      ? `Current operational state as of ${this.timestamp(generatedAt)}.`
      : 'Current operational state.';
  });

  /**
   * Quick actions. Every existing Home action is preserved, and each one that
   * navigates now actually navigates.
   *
   * Mutations stay permission-gated on the real capability that performs them
   * (`manage_assets`, `assign_assets`, `manage_tickets`), so a read-only role
   * is never offered an action the backend would reject.
   */
  private readonly quickActions: QuickAction[] = [
    { label: 'Create asset', icon: 'add-circle-outline', route: '/assets', permission: 'manage_assets' },
    { label: 'Scan asset', icon: 'qr-code-outline', route: '/assets', permission: 'view_assets' },
    { label: 'Assign asset', icon: 'person-add-outline', route: '/assets', permission: 'assign_assets' },
    { label: 'Create request', icon: 'document-text-outline', route: '/requests', permission: 'manage_tickets' },
    { label: 'Assets', icon: 'cube-outline', route: '/assets', permission: 'view_assets' },
    { label: 'Inventory', icon: 'cart-outline', route: '/inventory', permission: 'view_inventory' },
    { label: 'Requests', icon: 'document-text-outline', route: '/requests', permission: 'view_tickets' },
    { label: 'Maintenance', icon: 'wrench-outline', route: '/maintenance', permission: 'view_maintenance' },
    { label: 'Reports', icon: 'stats-chart-outline', route: '/reports', permission: 'view_reports' },
    { label: 'People', icon: 'people-outline', route: '/people', permission: 'view_users' },
    { label: 'Locations', icon: 'location-outline', route: '/locations', permission: 'view_locations' },
    { label: 'Notifications', icon: 'notifications-outline', route: '/notifications' },
    { label: 'Toggle theme', icon: 'sunny-outline', action: 'theme' },
  ];

  readonly visibleQuickActions = computed(() =>
    this.quickActions.filter(action => !action.permission || this.authorization.hasPermission(action.permission)),
  );

  ngOnInit(): void {
    if (this.canView()) {
      this.load();
    }
  }

  // ─── Data loading ───────────────────────────────────────────────────────────

  /**
   * Fetch the snapshot.
   *
   * No `limit` is sent: the backend owns its own default, echoes the applied
   * limit back on every queue, and the page reads that value rather than
   * assuming one. A first load shows skeletons; a refresh keeps the current
   * snapshot on screen and only marks the page as refreshing, so the workspace
   * never blanks and never shifts while data is replaced.
   *
   * Two guards, in order: a caller without `view_dashboard` never reaches the
   * network at all, and repeated calls while a request is in flight are dropped
   * rather than queued — which is what keeps the Refresh control from firing
   * duplicates.
   */
  private load(): void {
    if (!this.canView() || this.inFlight) {
      return;
    }

    const hasSnapshot = this.hasSnapshot();
    this.state.update(state => ({
      ...state,
      loading: !hasSnapshot,
      refreshing: hasSnapshot,
      error: null,
    }));
    this.inFlight = true;

    this.commandCenter.getSnapshot().subscribe({
      next: response => {
        this.inFlight = false;
        this.state.set({ data: response.data, loading: false, refreshing: false, error: null });
      },
      error: error => {
        this.inFlight = false;
        // A failed refresh keeps the previous snapshot visible instead of
        // replacing a working view with an error page; the message explains
        // that the figures may be stale.
        this.state.update(state => ({
          ...state,
          loading: false,
          refreshing: false,
          error: mapCommandCenterError(error),
        }));
      },
    });
  }

  /** Manual refresh (the header control, and the retry action after a failure). */
  refresh(): void {
    this.load();
  }

  go(route: string): void {
    void this.router.navigate([route]);
  }

  // ─── Queue rows ─────────────────────────────────────────────────────────────

  /**
   * Resolve a queue row's navigation target.
   *
   * Only two queue types address a record an existing workspace can select:
   * a ticket row (`/requests?selected={ticket id}`) and a maintenance-request
   * row (`/maintenance?selected={request id}`). Both use the `selected` query
   * parameter the Requests, Maintenance, Assets, and Inventory pages already
   * read. An asset-assignment row carries the assignment's own id, which is not
   * an asset id, so it stays read-only.
   */
  private resolveRow(item: CommandCenterQueueItem): QueueRow {
    if (item.type === 'ticket') {
      return { item, target: ['/requests'], queryParams: { selected: String(item.id) } };
    }

    if (item.type === 'maintenance_request') {
      return { item, target: ['/maintenance'], queryParams: { selected: String(item.id) } };
    }

    return { item, target: null, queryParams: null };
  }

  /**
   * Navigate to a queue row's workspace.
   *
   * The id is not an authorization decision: the destination route guard and the
   * destination endpoint remain the authority, and a caller who cannot open the
   * record sees that workspace's own Access Restricted state.
   */
  openQueueRow(row: QueueRow): void {
    if (!row.target) {
      return;
    }

    void this.router.navigate(row.target, { queryParams: row.queryParams });
  }

  // ─── Activity ───────────────────────────────────────────────────────────────

  /**
   * Display label for an event action.
   *
   * Unknown actions fall back to the stored value, so a vocabulary the frontend
   * has not seen is still shown truthfully instead of being hidden or guessed.
   */
  activityLabel(item: CommandCenterActivityItem): string {
    return ACTIVITY_ACTION_LABELS[item.action] ?? item.action;
  }

  activityIcon(item: CommandCenterActivityItem): string {
    return ACTIVITY_TYPE_ICONS[item.type] ?? 'ellipse-outline';
  }

  /**
   * A transition reads as "From → To" when the source stored both ends, and
   * falls back to the label alone when it did not.
   */
  activityTransition(item: CommandCenterActivityItem): string | null {
    if (item.old_status === null && item.new_status === null) {
      return null;
    }

    return `${item.old_status ?? '—'} → ${item.new_status ?? '—'}`;
  }

  // ─── Quick actions ──────────────────────────────────────────────────────────

  onQuickAction(action: QuickAction): void {
    if (action.action === 'theme') {
      this.theme.toggle();
      return;
    }

    if (action.route) {
      void this.router.navigate([action.route]);
    }
  }

  // ─── Presentation helpers ───────────────────────────────────────────────────

  /**
   * A count as the API returned it. Grouping separators are presentation only;
   * the underlying number is never rounded, scaled, or derived.
   */
  count(value: number): string {
    return value.toLocaleString();
  }

  /** Snapshot timestamp from the API, rendered for reading. */
  timestamp(value: string | null | undefined): string {
    if (!value) {
      return '—';
    }

    const parsed = new Date(value);
    return Number.isNaN(parsed.getTime()) ? value : parsed.toLocaleString();
  }

  /**
   * Humanize a stored value for display only. The raw value stays in the DOM as
   * `data-status`, so nothing is renamed in the data.
   */
  humanize(value: string): string {
    return value
      .replace(/[_-]+/g, ' ')
      .trim()
      .replace(/\b\w/g, character => character.toUpperCase());
  }
}
