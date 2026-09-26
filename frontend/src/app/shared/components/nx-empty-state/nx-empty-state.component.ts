import { Component, input } from '@angular/core';
import { CommonModule } from '@angular/common';
import { IonIcon } from '@ionic/angular';

/**
 * NxEmptyState — placeholder for empty lists/results.
 *
 * Used when: no assets, no requests, no results, no activity.
 */
@Component({
  selector: 'app-nx-empty-state',
  standalone: true,
  imports: [CommonModule, IonIcon],
  template: `
    <div class="nx-empty-state">
      @if (icon()) {
        <div class="nx-empty-state-icon">
          <ion-icon [name]="icon()"></ion-icon>
        </div>
      }
      @if (title()) {
        <h3 class="nx-empty-state-title">{{ title() }}</h3>
      }
      @if (description()) {
        <p class="nx-empty-state-description">{{ description() }}</p>
      }
      @if (showActions()) {
        <div class="nx-empty-state-actions">
          <ng-content select="[nx-empty-action]"></ng-content>
        </div>
      }
    </div>
  `,
  styles: [`
    .nx-empty-state {
      display: flex; flex-direction: column; align-items: center;
      justify-content: center; padding: 40px 24px;
      text-align: center; gap: 12px;
    }
    .nx-empty-state-icon { color: var(--nx-text-muted); opacity: 0.5; font-size: 32px; }
    .nx-empty-state-title { font-size: 16px; font-weight: 600; color: var(--nx-text-secondary); margin: 0; }
    .nx-empty-state-description { font-size: 14px; color: var(--nx-text-muted); max-width: 320px; margin: 0; }
    .nx-empty-state-actions { margin-top: 8px; }
  `],
})
export class NxEmptyStateComponent {
  readonly icon = input('');
  readonly title = input('');
  readonly description = input('');
  readonly showActions = input(false);
}
