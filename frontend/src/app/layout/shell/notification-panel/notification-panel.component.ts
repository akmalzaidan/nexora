import { Component, ElementRef, HostListener, OnInit, inject, signal, output } from '@angular/core';
import { CommonModule } from '@angular/common';
import { Router } from '@angular/router';
import { IonIcon } from '@ionic/angular';

import {
  NotificationService,
  NotificationInboxService,
  NexoraNotification,
  NotificationErrorInfo,
  formatNotificationTime,
  notificationTypeMeta,
  mapNotificationError,
} from '../../../core/services/notification.service';
import { NotificationNavigationService } from '../../../core/services/notification-navigation.service';
import { NxToastService } from '../../../shared/components/nx-toast/nx-toast.component';
import { NxButtonComponent } from '../../../shared/components/nx-button/nx-button.component';
import { NxEmptyStateComponent } from '../../../shared/components/nx-empty-state/nx-empty-state.component';

const PREVIEW_LIMIT = 6;

/**
 * NotificationPanelComponent — the bell's preview panel.
 *
 * Renders the 5–10 latest notifications (compact), a Mark all as read action
 * (only while un-read exist), and a View all footer. Opening the panel is the
 * refresh trigger: it re-fetches the preview and the backend unread count.
 * Clicking an unread row marks it read first, then opens the related record.
 * The panel mounts only while open, so outside-click and Escape close it.
 */
@Component({
  selector: 'app-notification-panel',
  standalone: true,
  imports: [CommonModule, IonIcon, NxButtonComponent, NxEmptyStateComponent],
  templateUrl: './notification-panel.component.html',
  styleUrl: './notification-panel.component.scss',
})
export class NotificationPanelComponent implements OnInit {
  private readonly service = inject(NotificationService);
  private readonly inbox = inject(NotificationInboxService);
  private readonly navigation = inject(NotificationNavigationService);
  private readonly router = inject(Router);
  private readonly toast = inject(NxToastService);
  readonly elementRef = inject(ElementRef<HTMLElement>);

  /** Ask the shell to close (unmount) the panel. */
  readonly requestClose = output<void>();

  readonly notifications = signal<NexoraNotification[]>([]);
  readonly loading = signal(true);
  readonly error = signal<NotificationErrorInfo | null>(null);
  readonly markAllBusy = signal(false);

  readonly unreadCount = this.inbox.unreadCount;
  readonly skeletonRows = [1, 2, 3];
  readonly time = formatNotificationTime;
  readonly meta = notificationTypeMeta;

  ngOnInit(): void {
    this.refresh();
  }

  /** Load the preview list and refresh the backend unread count. */
  refresh(): void {
    this.loading.set(true);
    this.error.set(null);
    this.inbox.refreshUnreadCount();
    this.service.listNotifications({ page: 1, per_page: PREVIEW_LIMIT }).subscribe({
      next: (res) => {
        this.loading.set(false);
        if (res.success) {
          this.notifications.set(res.data.items);
        }
      },
      error: (err) => {
        this.loading.set(false);
        this.error.set(mapNotificationError(err));
      },
    });
  }

  /** Mark all as read. On success re-syncs with the backend; on failure the count is kept. */
  markAll(): void {
    if (this.unreadCount() <= 0 || this.markAllBusy()) return;
    this.markAllBusy.set(true);
    this.service.markAllAsRead().subscribe({
      next: (res) => {
        this.markAllBusy.set(false);
        if (res.success) {
          this.inbox.refreshUnreadCount();
          this.toast.success('All notifications read', `Marked ${res.data.count} notification${res.data.count === 1 ? '' : 's'} as read.`);
          this.reloadSilently();
        }
      },
      error: (err) => {
        this.markAllBusy.set(false);
        this.toast.warning('Could not mark as read', mapNotificationError(err).message);
      },
    });
  }

  /** Clicking a row: mark read if unread, then open the related record. */
  onItemClick(item: NexoraNotification): void {
    if (!item.is_read) {
      this.service.markAsRead(item.id).subscribe({
        next: (res) => {
          if (res.success) {
            const updated = res.data;
            this.inbox.refreshUnreadCount();
            this.notifications.update(list => list.map(n => (n.id === updated.id ? updated : n)));
            this.openRelated(updated);
          }
        },
        error: (err) => {
          this.toast.warning('Could not open notification', mapNotificationError(err).message);
        },
      });
      return;
    }
    this.openRelated(item);
  }

  viewAll(): void {
    this.requestClose.emit();
    void this.router.navigate(['/notifications']);
  }

  @HostListener('document:keydown.escape')
  onEscape(): void {
    this.requestClose.emit();
  }

  @HostListener('document:mousedown', ['$event'])
  onDocumentClick(event: MouseEvent): void {
    if (!this.elementRef.nativeElement.contains(event.target as Node)) {
      this.requestClose.emit();
    }
  }

  private openRelated(item: NexoraNotification): void {
    const opened = this.navigation.openRelated(item);
    if (!opened) {
      this.toast.info('No related record available', 'This notification does not link to a record.');
    }
    this.requestClose.emit();
  }

  private reloadSilently(): void {
    this.error.set(null);
    this.service.listNotifications({ page: 1, per_page: PREVIEW_LIMIT }).subscribe({
      next: (res) => {
        if (res.success) {
          this.notifications.set(res.data.items);
        }
      },
      error: (err) => {
        this.error.set(mapNotificationError(err));
      },
    });
  }
}