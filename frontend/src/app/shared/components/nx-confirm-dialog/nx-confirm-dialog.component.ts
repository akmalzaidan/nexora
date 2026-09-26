import { Component, input, output } from '@angular/core';
import { CommonModule } from '@angular/common';
import { IonIcon, IonButton } from '@ionic/angular';
import { NxButtonComponent } from '../nx-button/nx-button.component';

/**
 * NxConfirmDialog — destructive action confirmation dialog.
 *
 * Usage:
 *   <app-nx-confirm-dialog
 *     title="Delete asset?"
 *     message="This action cannot be undone."
 *     confirmLabel="Delete"
 *     variant="danger"
 *     (confirm)="onConfirm()"
 *     (cancel)="onCancel()"
 *   >
 *   </app-nx-confirm-dialog>
 */
@Component({
  selector: 'app-nx-confirm-dialog',
  standalone: true,
  imports: [CommonModule, IonIcon, IonButton, NxButtonComponent],
  template: `
    @if (open()) {
      <div class="nx-confirm-dialog" role="dialog" aria-modal="true" [attr.aria-label]="title()">
        <div class="nx-confirm-dialog-backdrop" (click)="nxCancel.emit()"></div>
        <div class="nx-confirm-dialog-content">
          @if (title()) {
            <h3 class="nx-confirm-dialog-title">{{ title() }}</h3>
          }
          @if (message()) {
            <p class="nx-confirm-dialog-message">{{ message() }}</p>
          }
          <div class="nx-confirm-dialog-actions">
            <app-nx-button variant="ghost" (nxClick)="nxCancel.emit()">
              {{ cancelLabel() }}
            </app-nx-button>
            <app-nx-button
              [variant]="confirmVariant()"
              (click)="confirm.emit()"
            >
              {{ confirmLabel() }}
            </app-nx-button>
          </div>
        </div>
      </div>
    }
  `,
  styles: [`
    .nx-confirm-dialog {
      position: fixed; inset: 0; z-index: 400;
      display: flex; align-items: center; justify-content: center; padding: 24px;
    }
    .nx-confirm-dialog-backdrop {
      position: absolute; inset: 0;
      background: color-mix(in srgb, var(--nx-bg) 70%, transparent);
      backdrop-filter: blur(2px);
    }
    .nx-confirm-dialog-content {
      position: relative; background: var(--nx-surface-elevated);
      border: 1px solid var(--nx-border); border-radius: 12px;
      padding: 20px; max-width: 400px; width: 100%;
      box-shadow: 0 20px 25px rgba(0,0,0,0.3);
      animation: nx-dialog-in 200ms cubic-bezier(0.4,0,0.2,1);
      @keyframes nx-dialog-in { from { opacity: 0; transform: scale(0.95) translateY(4px); } to { opacity: 1; transform: scale(1) translateY(0); } }
    }
    .nx-confirm-dialog-title { font-size: 18px; font-weight: 600; color: var(--nx-text); margin: 0 0 8px; }
    .nx-confirm-dialog-message { font-size: 14px; color: var(--nx-text-secondary); line-height: 1.625; margin: 0; }
    .nx-confirm-dialog-actions { display: flex; gap: 8px; justify-content: flex-end; margin-top: 16px; }
  `],
})
export class NxConfirmDialogComponent {
  readonly title = input('Confirm action');
  readonly message = input('');
  readonly confirmLabel = input('Confirm');
  readonly cancelLabel = input('Cancel');
  readonly confirmVariant = input<'primary' | 'danger' | 'secondary'>('danger');
  readonly open = input(false);

  readonly confirm = output<void>();
  readonly nxCancel = output<void>();
}
