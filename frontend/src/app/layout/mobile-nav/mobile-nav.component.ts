import { Component, inject, computed } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterModule } from '@angular/router';
import { IonIcon } from '@ionic/angular';
import { AuthorizationService } from '../../core/services/authorization.service';

interface MobileNavItem {
  label: string;
  route: string;
  icon: string;
  permission?: string;
  /** Marker for the central action button (no route). */
  action?: boolean;
}

@Component({
  selector: 'app-mobile-nav',
  standalone: true,
  imports: [CommonModule, RouterModule, IonIcon],
  templateUrl: './mobile-nav.component.html',
  styleUrl: './mobile-nav.component.scss',
})
export class MobileNavComponent {
  private readonly authorization = inject(AuthorizationService);

  readonly items: MobileNavItem[] = [
    { label: 'Home', route: '/home', icon: 'home-outline' },
    { label: 'Assets', route: '/assets', icon: 'cube-outline', permission: 'view_assets' },
    { label: '', route: '', icon: 'add-outline', action: true },
    { label: 'Ops', route: '/requests', icon: 'document-text-outline', permission: 'view_tickets' },
    { label: 'Me', route: '/profile', icon: 'person-outline' },
  ];

  readonly visibleItems = computed(() =>
    this.items.filter(item => !item.permission || this.authorization.hasPermission(item.permission)),
  );
}
