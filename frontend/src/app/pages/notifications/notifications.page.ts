import { Component, inject, signal, computed, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { ActivatedRoute, Router } from '@angular/router';
import { IonIcon } from '@ionic/angular';

import {
  NotificationService,
  NotificationInboxService,
  NexoraNotification,
  NotificationErrorInfo,
  NotificationListFilters,
  NotificationTypeGroup,
  notificationTypeMeta,
  notificationTypeGroups,
  formatNotificationTime,
  mapNotificationError,
} from '../../core/services/notification.service';
import { NotificationNavigationService } from '../../core/services/notification-navigation.service';
import { CommandPaletteService } from '../../core/services/command-palette.service';

import { NxButtonComponent } from '../../shared/components/nx-button/nx-button.component';
import { NxEmptyStateComponent } from '../../shared/components/nx-empty-state/nx-empty-state.component';
import { NxErrorStateComponent } from '../../shared/components/nx-error-state/nx-error-state.component';
import { NxToastService } from '../../shared/components/nx-toast/nx-toast.component';

type ReadFilter = 'all' | 'unread' | 'read';

const READ_FILTER_OPTIONS: Array<{ value: ReadFilter; label: string }> = [
  { value: 'all', label: 'All' },
  { value: 'unread', label: 'Unread' },
  { value: 'read', label: 'Read' },
];

/**
 * NotificationsPage — the Notifications & Operational Alerts inbox.
 *
 * A master/detail workspace over GET /notifications: read/type filters sent to
 * the backend exactly as the API expects, backend-driven pagination, and a
 * per-notification detail. Read state is always backend-authoritative — the
 * frontend never derives counts and never performs optimistic zeroing, so a
 * failed mark-known keeps the previous count.
 */
@Component({
  selector: 'app-notifications',
  standalone: true,
  imports: [CommonModule, IonIcon, NxButtonComponent, NxEmptyStateComponent, NxErrorStateComponent],
  templateUrl: './notifications.page.html',
  styleUrl: './notifications.page.scss',
})
export class NotificationsPage implements OnInit {
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly service = inject(NotificationService);
  private readonly inbox = inject(NotificationInboxService);
  private readonly navigation = inject(NotificationNavigationService);
  private readonly palette = inject(CommandPaletteService);
  private readonly toast = inject(NxToastService);

  readonly skeletonRows = [1, 2, 3, 4, 5];
  readonly perPage = 15;
  readonly unreadCount = this.inbox.unreadCount;
  readonly time = formatNotificationTime;
  readonly meta = notificationTypeMeta;
  readonly READ_FILTERS = READ_FILTER_OPTIONS;
  readonly TYPE_GROUPS: NotificationTypeGroup[] = notificationTypeGroups();

  // ─── List state ────────────────────────────────────────────────────────────

  readonly notifications = signal<NexoraNotification[]>([]);
  readonly total = signal(0);
  readonly currentPage = signal(1);
  readonly lastPage = signal(1);
  readonly loading = signal(true);
  readonly refreshing = signal(false);
  readonly error = signal<NotificationErrorInfo | null>(null);
  readonly readFilter = signal<ReadFilter>('all');
  readonly typeFilter = signal<string>('');
  readonly activeFilterCount = computed(
    () => (this.readFilter() !== 'all' ? 1 : 0) + (this.typeFilter() ? 1 : 0),
  );

  // ─── Detail state ──────────────────────────────────────────────────────────

  readonly selectedId = signal<number | null>(null);
  readonly detailNotification = signal<NexoraNotification | null>(null);
  readonly detailLoading = signal(false);
  readonly detailError = signal<NotificationErrorInfo | null>(null);
  readonly markAllBusy = signal(false);

  readonly selectedNotification = computed(() => {
    const id = this.selectedId();
    return id === null ? null : this.detailNotification();
  });

  readonly detailIsUnread = computed(() => (this.selectedNotification()?.is_read ?? false) === false);

  /** Deep-link target for the selected notification, when one exists. */
  readonly detailTarget = computed(() => {
    const notification = this.selectedNotification();
    return notification ? this.navigation.resolveTarget(notification) : null;
  });

  /** Payload facts worth surfacing (backend keys only; unknown keys are ignored). */
  readonly detailFacts = computed(() => {
    const notification = this.detailNotification();
    if (!notification) return [];
    const data = notification.data ?? {};
    const facts: Array<{ label: string; value: string }> = [];
    if (data['ticket_number']) facts.push({ label: 'Ticket', value: String(data['ticket_number']) });
    if (data['status']) facts.push({ label: 'Status', value: String(data['status']) });
    if (data['ticket_id']) facts.push({ label: 'Ticket ID', value: String(data['ticket_id']) });
    if (data['maintenance_request_id']) facts.push({ label: 'Request ID', value: String(data['maintenance_request_id']) });
    if (data['asset_id']) facts.push({ label: 'Asset ID', value: String(data['asset_id']) });
    if (data['assignment_id']) facts.push({ label: 'Assignment ID', value: String(data['assignment_id']) });
    return facts;
  });

  trackById = (_index: number, item: { id: number }): number => item.id;

  ngOnInit(): void {
    this.loadNotifications(true);
    this.route.queryParams.subscribe(params => {
      const raw = params['selected'];
      if (raw === undefined || raw === null) return;
      const id = Number(raw);
      if (!Number.isInteger(id) || id <= 0) return;
      if (this.selectedId() === id) return;
      this.selectNotification(id);
    });
  }

  // ─── Loading ───────────────────────────────────────────────────────────────

  loadNotifications(refresh = false): void {
    if (refresh) {
      this.loading.set(true);
    }
    const filters: NotificationListFilters = {
      page: this.currentPage(),
      per_page: this.perPage,
    };
    if (this.readFilter() === 'read') filters.read = true;
    else if (this.readFilter() === 'unread') filters.read = false;
    if (this.typeFilter()) filters.type = this.typeFilter();

    this.service.listNotifications(filters).subscribe({
      next: (res) => {
        this.loading.set(false);
        this.refreshing.set(false);
        if (res.success) {
          this.notifications.set(res.data.items);
          this.total.set(res.data.pagination.total);
          this.currentPage.set(res.data.pagination.current_page);
          this.lastPage.set(res.data.pagination.last_page);
          this.error.set(null);
        }
      },
      error: (err) => {
        this.loading.set(false);
        this.refreshing.set(false);
        const info = mapNotificationError(err);
        this.error.set(info);
        if (this.notifications().length === 0) {
          this.toast.danger('Unable to load notifications', info.message);
        }
      },
    });
  }

  refresh(): void {
    this.refreshing.set(true);
    this.loadNotifications();
  }

  setReadFilter(value: string): void {
    this.readFilter.set(value as ReadFilter);
    this.gotoPage(1);
  }

  setTypeFilter(value: string): void {
    this.typeFilter.set(value);
    this.gotoPage(1);
  }

  clearFilters(): void {
    this.readFilter.set('all');
    this.typeFilter.set('');
    this.gotoPage(1);
  }

  gotoPage(page: number): void {
    const last = this.lastPage();
    const target = page < 1 ? 1 : page > last ? last : page;
    this.currentPage.set(target);
    this.loadNotifications(true);
  }

  // ─── Selection / detail ────────────────────────────────────────────────────

  /** Row click: mark unread notifications read first, then open the detail. */
  onItemClick(item: NexoraNotification): void {
    if (!item.is_read) {
      this.service.markAsRead(item.id).subscribe({
        next: (res) => {
          if (res.success) {
            this.inbox.refreshUnreadCount();
            this.applyUpdated(res.data);
            if (this.readFilter() === 'unread') {
              this.loadNotifications();
            }
            this.selectNotification(item.id);
          }
        },
        error: (err) => {
          this.toast.warning('Could not mark as read', mapNotificationError(err).message);
        },
      });
      return;
    }
    this.selectNotification(item.id);
  }

  selectNotification(id: number): void {
    this.selectedId.set(id);
    this.router.navigate([], {
      queryParams: { selected: id },
      queryParamsHandling: 'merge',
      replaceUrl: true,
    });
    this.loadDetail(id);
  }

  clearSelection(): void {
    this.selectedId.set(null);
    this.detailNotification.set(null);
    this.detailError.set(null);
    this.router.navigate([], {
      queryParams: { selected: null },
      queryParamsHandling: 'merge',
      replaceUrl: true,
    });
  }

  loadDetail(id: number): void {
    this.detailNotification.set(null);
    this.detailLoading.set(true);
    this.detailError.set(null);
    this.service.getNotification(id).subscribe({
      next: (res) => {
        this.detailLoading.set(false);
        if (res.success) {
          this.detailNotification.set(res.data);
        }
      },
      error: (err) => {
        this.detailLoading.set(false);
        this.detailError.set(mapNotificationError(err));
      },
    });
  }

  markDetailRead(): void {
    const notification = this.detailNotification();
    if (!notification || notification.is_read) return;
    this.service.markAsRead(notification.id).subscribe({
      next: (res) => {
        if (res.success) {
          this.inbox.refreshUnreadCount();
          this.applyUpdated(res.data);
          if (this.readFilter() === 'unread') {
            this.loadNotifications();
          }
        }
      },
      error: (err) => {
        this.toast.warning('Could not mark as read', mapNotificationError(err).message);
      },
    });
  }

  markAllRead(): void {
    if (this.unreadCount() <= 0 || this.markAllBusy()) return;
    this.markAllBusy.set(true);
    this.service.markAllAsRead().subscribe({
      next: (res) => {
        this.markAllBusy.set(false);
        if (res.success) {
          this.inbox.refreshUnreadCount();
          this.toast.success('All notifications read', `Marked ${res.data.count} notification${res.data.count === 1 ? '' : 's'} as read.`);
          this.loadNotifications();
        }
      },
      error: (err) => {
        this.markAllBusy.set(false);
        this.toast.warning('Could not mark as read', mapNotificationError(err).message);
      },
    });
  }

  openDetailTarget(): void {
    if (!this.selectedNotification()) return;
    const opened = this.navigation.openRelated(this.selectedNotification()!);
    if (!opened) {
      this.toast.info('No related record available', 'This notification does not link to a record.');
    }
  }

  openPalette(): void {
    this.palette.open();
  }

  private applyUpdated(updated: NexoraNotification): void {
    this.notifications.update(list => list.map(n => (n.id === updated.id ? updated : n)));
    const current = this.detailNotification();
    if (current && current.id === updated.id) {
      this.detailNotification.set(updated);
    }
  }
}