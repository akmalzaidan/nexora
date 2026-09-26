import { Component, inject, signal, computed, effect, OnInit, CUSTOM_ELEMENTS_SCHEMA } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterModule } from '@angular/router';
import { SidebarComponent } from '../sidebar/sidebar.component';
import { MobileNavComponent } from '../mobile-nav/mobile-nav.component';
import { CommandPaletteComponent } from '../../command-palette/command-palette.component';
import { NxToastContainerComponent } from '../../shared/components/nx-toast/nx-toast.component';
import { NotificationPanelComponent } from './notification-panel/notification-panel.component';
import { NotificationInboxService } from '../../core/services/notification.service';
import { ThemeService } from '../../core/services/theme.service';
import { AuthService } from '../../core/services/auth.service';

@Component({
  selector: 'app-shell',
  standalone: true,
  imports: [
    CommonModule,
    RouterModule,
    SidebarComponent,
    MobileNavComponent,
    CommandPaletteComponent,
    NxToastContainerComponent,
    NotificationPanelComponent,
  ],
  schemas: [CUSTOM_ELEMENTS_SCHEMA],
  templateUrl: './shell.component.html',
  styleUrl: './shell.component.scss',
})
export class ShellComponent implements OnInit {
  readonly theme = inject(ThemeService);
  readonly auth = inject(AuthService);
  readonly inbox = inject(NotificationInboxService);

  /** Unread notification count (always from the backend endpoint). */
  readonly unreadCount = this.inbox.unreadCount;

  /** Whether the bell's preview panel is open. */
  readonly notificationsOpen = signal(false);

  /** Accessible label for the bell, including the unread count. */
  readonly notificationsAriaLabel = computed(() => {
    const count = this.unreadCount();
    return count > 0 ? `Notifications, ${count} unread` : 'Notifications';
  });

  /** Whether the sidebar is expanded (tablet). */
  readonly sidebarExpanded = signal(false);

  readonly isDark = this.theme.isDark;

  /** Mock greeting based on time of day. */
  readonly greeting = this.getGreeting();

  constructor() {
    // Session isolation: when the session ends, cached notification state
    // must never leak to the next user.
    effect(() => {
      if (!this.auth.isAuthenticated()) {
        this.inbox.reset();
        this.notificationsOpen.set(false);
      }
    });
  }

  ngOnInit(): void {
    if (this.auth.isAuthenticated()) {
      this.inbox.loadUnreadCount();
    }
  }

  /** Badge text with a 99+ cap. */
  badgeLabel(count: number): string {
    if (count <= 0) return '';
    return count > 99 ? '99+' : String(count);
  }

  toggleNotifications(): void {
    this.notificationsOpen.set(!this.notificationsOpen());
  }

  closeNotifications(): void {
    this.notificationsOpen.set(false);
  }

  private getGreeting(): string {
    const hour = new Date().getHours();
    if (hour < 12) return 'Good morning';
    if (hour < 17) return 'Good afternoon';
    return 'Good evening';
  }

  /** Sign the current user out through the backend and return to /login. */
  async logout(): Promise<void> {
    await this.auth.logout();
  }
}
