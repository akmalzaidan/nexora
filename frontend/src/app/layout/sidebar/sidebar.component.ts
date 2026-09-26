import { Component, inject, signal, computed } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterModule } from '@angular/router';
import { IonIcon } from '@ionic/angular';
import { AuthService } from '../../core/services/auth.service';
import { AuthorizationService } from '../../core/services/authorization.service';

interface NavItem {
  label: string;
  route: string;
  icon: string;
  permission?: string;
  children?: NavItem[];
}

@Component({
  selector: 'app-sidebar',
  standalone: true,
  imports: [CommonModule, RouterModule, IonIcon],
  templateUrl: './sidebar.component.html',
  styleUrl: './sidebar.component.scss',
})
export class SidebarComponent {
  readonly auth = inject(AuthService);
  private readonly authorization = inject(AuthorizationService);

  /** Collapsed state for tablet. */
  readonly isCollapsed = signal(false);

  readonly navItems: NavItem[] = [
    {
      label: 'Home',
      route: '/home',
      icon: 'home-outline',
    },
    {
      label: 'Assets',
      route: '/assets',
      icon: 'cube-outline',
      permission: 'view_assets',
    },
    {
      label: 'Inventory',
      route: '/inventory',
      icon: 'cart-outline',
      permission: 'view_inventory',
    },
    {
      label: 'Requests',
      route: '/requests',
      icon: 'document-text-outline',
      permission: 'view_tickets',
    },
    {
      label: 'Maintenance',
      route: '/maintenance',
      icon: 'wrench-outline',
      permission: 'view_maintenance',
    },
    {
      label: 'Reports',
      route: '/reports',
      icon: 'stats-chart-outline',
      permission: 'view_reports',
    },
    {
      label: 'Organization',
      route: '/people',
      icon: 'people-outline',
      children: [
        { label: 'People', route: '/people', icon: 'person-outline', permission: 'view_users' },
        { label: 'Locations', route: '/locations', icon: 'location-outline', permission: 'view_locations' },
      ],
    },
    {
      label: 'Settings',
      route: '/settings',
      icon: 'settings-outline',
    },
  ];

  readonly bottomItems: NavItem[] = [
    {
      label: 'Profile',
      route: '/profile',
      icon: 'person-outline',
    },
  ];

  /** Nav items filtered by the authenticated user's permissions. */
  readonly visibleNavItems = computed(() => {
    const isVisible = (item: NavItem): boolean =>
      !item.permission || this.authorization.hasPermission(item.permission);

    return this.navItems
      .filter(isVisible)
      .map(item =>
        item.children ? { ...item, children: item.children.filter(isVisible) } : item,
      )
      .filter(item => !item.children || item.children.length > 0);
  });

  getIcon(name: string): string {
    return name;
  }

  isActive(route: string): boolean {
    return this.auth.user() ? window.location.pathname === route : false;
  }

  isGroupActive(children: NavItem[]): boolean {
    return children.some(child => this.isActive(child.route));
  }

  toggleCollapse(): void {
    this.isCollapsed.update(v => !v);
  }
}
