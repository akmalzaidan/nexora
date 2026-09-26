import { Component, inject } from '@angular/core';
import { IonContent, IonHeader, IonIcon, IonTitle, IonToolbar } from '@ionic/angular';
import { ThemeService } from '../../core/services/theme.service';
import { NxButtonComponent } from '../../shared/components/nx-button/nx-button.component';
import { NxPanelComponent } from '../../shared/components/nx-panel/nx-panel.component';

@Component({
  selector: 'app-settings',
  standalone: true,
  imports: [IonHeader, IonToolbar, IonTitle, IonContent, IonIcon, NxButtonComponent, NxPanelComponent],
  template: `
    <ion-header>
      <ion-toolbar>
        <ion-title>Settings</ion-title>
      </ion-toolbar>
    </ion-header>
    <ion-content>
      <div style="max-width: 600px; margin: 0 auto; padding: 16px;">
        <app-nx-panel title="Appearance">
          <div style="display: flex; align-items: center; justify-content: space-between; padding: 12px 0;">
            <div>
              <div style="font-size: 14px; font-weight: 500; color: var(--nx-text);">Theme</div>
              <div style="font-size: 12px; color: var(--nx-text-muted); margin-top: 2px;">Choose your preferred visual theme</div>
            </div>
            <div style="display: flex; gap: 8px;">
              <button
                class="nx-theme-btn"
                [class.active]="theme.mode() === 'dark'"
                (click)="theme.setMode('dark')"
              >
                <ion-icon name="moon-outline"></ion-icon>
                <span>Dark</span>
              </button>
              <button
                class="nx-theme-btn"
                [class.active]="theme.mode() === 'light'"
                (click)="theme.setMode('light')"
              >
                <ion-icon name="sunny-outline"></ion-icon>
                <span>Light</span>
              </button>
              <button
                class="nx-theme-btn"
                [class.active]="theme.mode() === 'system'"
                (click)="theme.setMode('system')"
              >
                <ion-icon name="monitor-outline"></ion-icon>
                <span>System</span>
              </button>
            </div>
          </div>
        </app-nx-panel>

        <app-nx-panel title="Account">
          <div style="padding: 12px 0; border-bottom: 1px solid var(--nx-border-subtle);">
            <div style="font-size: 14px; font-weight: 500; color: var(--nx-text);">Profile settings</div>
            <div style="font-size: 12px; color: var(--nx-text-muted); margin-top: 4px;">Manage your profile and preferences</div>
          </div>
          <app-nx-button variant="secondary" style="width: 100%; margin-top: 12px;">Edit Profile</app-nx-button>
        </app-nx-panel>

        <app-nx-panel title="About">
          <div style="font-size: 13px; color: var(--nx-text-secondary); line-height: 1.6;">
            <p><strong>NEXORA</strong> v1.0.0</p>
            <p>Enterprise Operations Management Platform</p>
            <p style="margin-top: 8px; font-size: 12px; color: var(--nx-text-muted);">
              Angular 22 + Ionic 9 + Capacitor 8<br>
              Laravel 13 + PHP 8.3 + PostgreSQL 16
            </p>
          </div>
        </app-nx-panel>
      </div>
    </ion-content>
  `,
  styles: [`
    ion-toolbar { --background: var(--nx-surface); --color: var(--nx-text); }
    .nx-theme-btn {
      display: flex; align-items: center; gap: 6px;
      padding: 8px 14px; background: var(--nx-surface);
      border: 1px solid var(--nx-border); border-radius: 6px;
      cursor: pointer; font-family: 'Inter', sans-serif;
      font-size: 13px; font-weight: 500; color: var(--nx-text-secondary);
      transition: all 120ms ease;
      ion-icon { font-size: 16px; }
      &:hover { border-color: var(--nx-border-strong); color: var(--nx-text); }
      &.active { background: var(--nx-accent-subtle); border-color: var(--nx-accent); color: var(--nx-accent); }
    }
  `],
})
export class SettingsPage {
  readonly theme = inject(ThemeService);
}