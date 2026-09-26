import { Component, inject } from '@angular/core';
import { IonHeader, IonToolbar, IonTitle, IonContent } from '@ionic/angular';
import { NxBadgeComponent } from '../../shared/components/nx-badge/nx-badge.component';
import { NxPanelComponent } from '../../shared/components/nx-panel/nx-panel.component';
import { AuthService } from '../../core/services/auth.service';

@Component({
  selector: 'app-profile',
  standalone: true,
  imports: [IonHeader, IonToolbar, IonTitle, IonContent, NxBadgeComponent, NxPanelComponent],
  template: `
    <ion-header>
      <ion-toolbar>
        <ion-title>Profile</ion-title>
      </ion-toolbar>
    </ion-header>
    <ion-content>
      <div style="max-width: 600px; margin: 0 auto; padding: 16px;">
        <app-nx-panel title="Profile Information">
          <div class="nx-profile-identity">
            <div class="nx-profile-avatar">
              {{ auth.user()?.name?.charAt(0) || 'U' }}
            </div>
            <div class="nx-profile-details">
              <div class="nx-profile-name">{{ auth.user()?.name || '—' }}</div>
              <div class="nx-profile-email">{{ auth.user()?.email || '—' }}</div>
              <app-nx-badge [status]="auth.user()?.is_active ? 'active' : 'inactive'">
                {{ auth.user()?.is_active ? 'Active' : 'Inactive' }}
              </app-nx-badge>
            </div>
          </div>

          <div class="nx-profile-row">
            <span class="nx-profile-row-label">Role</span>
            <span class="nx-profile-row-value">{{ auth.roleName() ?? '—' }}</span>
          </div>
          <div class="nx-profile-row">
            <span class="nx-profile-row-label">Department</span>
            <span class="nx-profile-row-value">{{ auth.departmentName() ?? '—' }}</span>
          </div>
          <div class="nx-profile-row">
            <span class="nx-profile-row-label">Email</span>
            <span class="nx-profile-row-value nx-profile-row-mono">{{ auth.user()?.email || '—' }}</span>
          </div>
          <div class="nx-profile-row">
            <span class="nx-profile-row-label">Member since</span>
            <span class="nx-profile-row-value">{{ memberSince() }}</span>
          </div>
        </app-nx-panel>
      </div>
    </ion-content>
  `,
  styles: [`
    ion-toolbar { --background: var(--nx-surface); --color: var(--nx-text); }
    .nx-profile-identity {
      display: flex; align-items: center; gap: 16px;
      padding: 16px; border-bottom: 1px solid var(--nx-border-subtle);
    }
    .nx-profile-avatar {
      width: 56px; height: 56px; border-radius: 50%;
      background: var(--nx-accent); color: var(--nx-bg);
      font-size: 22px; font-weight: 700;
      display: flex; align-items: center; justify-content: center; flex-shrink: 0;
    }
    .nx-profile-details { display: flex; flex-direction: column; align-items: flex-start; gap: 4px; }
    .nx-profile-name { font-size: 16px; font-weight: 600; color: var(--nx-text); }
    .nx-profile-email { font-size: 13px; color: var(--nx-text-secondary); }
    .nx-profile-row {
      display: flex; align-items: center; justify-content: space-between;
      padding: 12px 16px; border-bottom: 1px solid var(--nx-border-subtle);
      &:last-child { border-bottom: none; }
    }
    .nx-profile-row-label { font-size: 13px; color: var(--nx-text-secondary); }
    .nx-profile-row-value { font-size: 13px; color: var(--nx-text); }
    .nx-profile-row-mono { font-family: 'JetBrains Mono', monospace; }
  `],
})
export class ProfilePage {
  readonly auth = inject(AuthService);

  memberSince(): string {
    const created = this.auth.user()?.created_at;
    if (!created) return '—';
    const date = new Date(created);
    if (Number.isNaN(date.getTime())) return '—';
    return date.toLocaleDateString(undefined, { year: 'numeric', month: 'long', day: 'numeric' });
  }
}