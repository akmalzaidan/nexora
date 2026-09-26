import { Component, input, output } from '@angular/core';
import { CommonModule } from '@angular/common';
import { IonIcon } from '@ionic/angular';
import { NxButtonComponent } from '../nx-button/nx-button.component';

/**
 * NxErrorState — error placeholder with optional retry.
 *
 * Used when: API errors, content fails to load.
 */
@Component({
  selector: 'app-nx-error-state',
  standalone: true,
  imports: [CommonModule, IonIcon, NxButtonComponent],
  template: `
    <div class="nx-error-state">
      @if (icon()) {
        <div class="nx-error-icon">
          <ion-icon [name]="icon()"></ion-icon>
        </div>
      }
      @if (title()) {
        <h3 class="nx-error-title">{{ title() }}</h3>
      }
      @if (message()) {
        <p class="nx-error-message">{{ message() }}</p>
      }
      @if (showRetry()) {
        <div class="nx-error-actions">
          <app-nx-button variant="secondary" (nxClick)="nxRetry.emit()">
            Retry
          </app-nx-button>
          <ng-content select="[nx-error-action]"></ng-content>
        </div>
      }
    </div>
  `,
  styles: [`
    .nx-error-state {
      display: flex; flex-direction: column; align-items: center;
      justify-content: center; padding: 32px 24px; gap: 12px; text-align: center;
    }
    .nx-error-icon { color: var(--nx-danger); opacity: 0.7; font-size: 28px; }
    .nx-error-title { font-size: 16px; font-weight: 600; color: var(--nx-danger); margin: 0; }
    .nx-error-message { font-size: 14px; color: var(--nx-text-muted); max-width: 400px; margin: 0; }
    .nx-error-actions { margin-top: 8px; display: flex; gap: 8px; align-items: center; }
  `],
})
export class NxErrorStateComponent {
  readonly icon = input('alert-circle-outline');
  readonly title = input('Something went wrong');
  readonly message = input('');
  readonly showRetry = input(false);

  readonly nxRetry = output<void>();
}
