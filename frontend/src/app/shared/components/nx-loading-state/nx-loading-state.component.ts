import { Component, input } from '@angular/core';
import { CommonModule } from '@angular/common';
import { IonSpinner } from '@ionic/angular';

/**
 * NxLoadingState — loading indicator for content areas.
 *
 * Supports: spinner, skeleton, or overlay mode.
 */
@Component({
  selector: 'app-nx-loading-state',
  standalone: true,
  imports: [CommonModule, IonSpinner],
  template: `
    <div class="nx-loading-state" [class.overlay]="mode() === 'overlay'">
      @if (mode() === 'spinner') {
        <div class="nx-loading-spinner">
          <ion-spinner name="crescent"></ion-spinner>
          @if (label()) {
            <p class="nx-loading-label">{{ label() }}</p>
          }
        </div>
      }
      @if (mode() === 'skeleton') {
        <div class="nx-loading-skeleton">
          <ng-content></ng-content>
        </div>
      }
      @if (mode() === 'overlay') {
        <div class="nx-loading-overlay-content">
          <ion-spinner name="crescent"></ion-spinner>
        </div>
      }
    </div>
  `,
  styles: [`
    .nx-loading-state { display: flex; align-items: center; justify-content: center; }
    .nx-loading-state.overlay { position: absolute; inset: 0; background: color-mix(in srgb, var(--nx-bg) 60%, transparent); z-index: 10; }
    .nx-loading-spinner { display: flex; flex-direction: column; align-items: center; gap: 12px; }
    ion-spinner { --color: var(--nx-accent); }
    .nx-loading-label { font-size: 13px; color: var(--nx-text-muted); margin: 0; }
    .nx-loading-skeleton { pointer-events: none; }
  `],
})
export class NxLoadingStateComponent {
  readonly mode = input<'spinner' | 'skeleton' | 'overlay'>('spinner');
  readonly label = input('');
}
