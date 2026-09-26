import { Injectable, signal, computed, inject } from '@angular/core';
import { Router } from '@angular/router';
import { AuthService } from './auth.service';
import { ThemeService } from './theme.service';
import { AuthorizationService } from './authorization.service';
import { NotificationService, NotificationInboxService, mapNotificationError } from './notification.service';
import { NxToastService } from '../../shared/components/nx-toast/nx-toast.component';

interface PaletteCommand {
  id: string;
  label: string;
  hint: string;
  group: string;
  permission?: string;
  action: () => void;
}

/**
 * CommandPaletteService manages the global command palette state.
 *
 * The command palette is toggled with Ctrl/Cmd + K and provides
 * keyboard-accessible navigation and actions. Navigation commands are
 * filtered by the authenticated user's permissions.
 */
@Injectable({ providedIn: 'root' })
export class CommandPaletteService {
  private readonly router = inject(Router);
  private readonly auth = inject(AuthService);
  private readonly theme = inject(ThemeService);
  private readonly authorization = inject(AuthorizationService);
  private readonly notifications = inject(NotificationService);
  private readonly inbox = inject(NotificationInboxService);
  private readonly toast = inject(NxToastService);

  /** Whether the command palette is currently open. */
  readonly isOpen = signal(false);

  /** The current search query. */
  readonly query = signal('');

  /** Active (highlighted) command index. */
  readonly activeIndex = signal(0);

  /**
   * Set the active (highlighted) command index.
   */
  setActive(index: number): void {
    this.activeIndex.set(index);
  }

  /**
   * Full list of commands, each with an id, label, hint, group, optional
   * permission requirement, and optional action.
   */
  private readonly commands = this.buildCommands();

  /**
   * Commands the current user is allowed to run (permission-filtered).
   */
  readonly allowedCommands = computed(() =>
    this.commands.filter(c => !c.permission || this.authorization.hasPermission(c.permission)),
  );

  private buildCommands(): PaletteCommand[] {
    const navigate = (path: string): void => {
      void this.router.navigate([path]);
    };

    return [
      // Navigation
      { id: 'nav-home', label: 'Go to Command Center', hint: 'Ctrl+H', group: 'Navigation', action: () => navigate('/home') },
      { id: 'nav-assets', label: 'Go to Assets', hint: '', group: 'Navigation', permission: 'view_assets', action: () => navigate('/assets') },
      { id: 'nav-inventory', label: 'Go to Inventory', hint: '', group: 'Navigation', permission: 'view_inventory', action: () => navigate('/inventory') },
      { id: 'nav-requests', label: 'Go to Requests', hint: '', group: 'Navigation', permission: 'view_tickets', action: () => navigate('/requests') },
      { id: 'nav-maintenance', label: 'Go to Maintenance', hint: '', group: 'Navigation', permission: 'view_maintenance', action: () => navigate('/maintenance') },
      { id: 'nav-people', label: 'Go to People', hint: '', group: 'Navigation', permission: 'view_users', action: () => navigate('/people') },
      { id: 'nav-locations', label: 'Go to Locations', hint: '', group: 'Navigation', permission: 'view_locations', action: () => navigate('/locations') },
      { id: 'nav-reports', label: 'View reports', hint: '', group: 'Navigation', permission: 'view_reports', action: () => navigate('/reports') },
      { id: 'nav-settings', label: 'Go to Settings', hint: '', group: 'Navigation', action: () => navigate('/settings') },

      // Asset Operations (placeholders)
      { id: 'asset-search', label: 'Search assets', hint: '', group: 'Assets', permission: 'view_assets', action: () => navigate('/assets') },
      { id: 'asset-create', label: 'Create asset', hint: '', group: 'Assets', permission: 'manage_assets', action: () => navigate('/assets') },
      { id: 'asset-scan', label: 'Scan asset QR', hint: '', group: 'Assets', permission: 'view_assets', action: () => navigate('/assets') },
      { id: 'asset-assign', label: 'Assign asset', hint: '', group: 'Assets', permission: 'manage_asset_assignments', action: () => navigate('/assets') },
      { id: 'asset-return', label: 'Return asset', hint: '', group: 'Assets', permission: 'manage_asset_assignments', action: () => navigate('/assets') },

      // Request Operations
      { id: 'request-create', label: 'Create request', hint: '', group: 'Requests', permission: 'view_tickets', action: () => navigate('/requests') },

      // Notifications
      { id: 'nav-notifications', label: 'View notifications', hint: '', group: 'Navigation', action: () => navigate('/notifications') },
      { id: 'notifications-mark-all-read', label: 'Mark all notifications as read', hint: '', group: 'Notifications', action: () => this.markAllNotificationsRead() },

      // System
      { id: 'theme-toggle', label: 'Toggle theme', hint: '', group: 'System', action: () => this.theme.toggle() },
      { id: 'logout', label: 'Log out', hint: '', group: 'System', action: () => void this.auth.logout() },
    ];
  }

  /** Mark every notification read via the backend; never optimistically zeroes. */
  private markAllNotificationsRead(): void {
    if (this.inbox.unreadCount() <= 0) {
      this.toast.info('No unread notifications', 'There is nothing to mark as read.');
      return;
    }
    this.notifications.markAllAsRead().subscribe({
      next: (res) => {
        if (res.success) {
          this.inbox.refreshUnreadCount();
          this.toast.success('All notifications read', `Marked ${res.data.count} notification${res.data.count === 1 ? '' : 's'} as read.`);
        }
      },
      error: (err) => {
        this.toast.warning('Could not mark as read', mapNotificationError(err).message);
      },
    });
  }

  /**
   * Filtered commands based on the current query.
   * Grouped by group name, preserving order.
   */
  readonly filteredCommands = computed(() => {
    const q = this.query().toLowerCase().trim();
    const candidates = this.allowedCommands();
    if (!q) return candidates;

    return candidates.filter(c =>
      c.label.toLowerCase().includes(q) ||
      c.hint.toLowerCase().includes(q) ||
      c.group.toLowerCase().includes(q)
    );
  });

  /** Whether there are filtered results. */
  readonly hasResults = computed(() => this.filteredCommands().length > 0);

  /**
   * Open the command palette and focus the search input.
   */
  open(): void {
    this.isOpen.set(true);
    this.query.set('');
    this.activeIndex.set(0);
  }

  /**
   * Close the command palette.
   */
  close(): void {
    this.isOpen.set(false);
    this.query.set('');
    this.activeIndex.set(0);
  }

  /**
   * Toggle the command palette.
   */
  toggle(): void {
    if (this.isOpen()) {
      this.close();
    } else {
      this.open();
    }
  }

  /**
   * Update the search query and reset active index.
   */
  setQuery(value: string): void {
    this.query.set(value);
    this.activeIndex.set(0);
  }

  /**
   * Move the active selection up.
   */
  moveUp(): void {
    const count = this.filteredCommands().length;
    if (count === 0) return;
    this.activeIndex.set((this.activeIndex() - 1 + count) % count);
  }

  /**
   * Move the active selection down.
   */
  moveDown(): void {
    const count = this.filteredCommands().length;
    if (count === 0) return;
    this.activeIndex.set((this.activeIndex() + 1) % count);
  }

  /**
   * Execute the currently active command.
   */
  executeActive(): void {
    const commands = this.filteredCommands();
    const index = this.activeIndex();
    if (commands.length === 0 || index >= commands.length) return;
    const command = commands[index];
    if (command) {
      command.action();
      this.close();
    }
  }
}
