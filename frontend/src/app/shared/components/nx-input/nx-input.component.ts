import { Component, input, output } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { IonIcon } from '@ionic/angular';

/**
 * NxInput — reusable form input primitive.
 *
 * Supports: label, hint, error, disabled, loading.
 */
@Component({
  selector: 'app-nx-input',
  standalone: true,
  imports: [CommonModule, FormsModule, IonIcon],
  template: `
    <div class="nx-input-wrapper">
      @if (label()) {
        <label class="nx-input-label">{{ label() }}</label>
      }
      <div class="nx-input-field" [class.nx-input-error]="error()" [class.nx-input-disabled]="disabled()">
        <ng-content select="[nx-input-prefix]"></ng-content>
        <input
          class="nx-input-control"
          [type]="type()"
          [placeholder]="placeholder()"
          [disabled]="disabled()"
          [readonly]="readonly()"
          [value]="value()"
          (input)="onInput($any($event.target).value)"
          (blur)="onBlur()"
          (focus)="onFocus()"
        />
        <ng-content select="[nx-input-suffix]"></ng-content>
        @if (error()) {
          <ion-icon class="nx-input-error-icon" name="alert-circle"></ion-icon>
        }
        @if (loading()) {
          <ion-icon class="nx-input-loading-icon" name="sync"></ion-icon>
        }
      </div>
      @if (hint() && !error()) {
        <p class="nx-input-hint">{{ hint() }}</p>
      }
      @if (error()) {
        <p class="nx-input-error-text">{{ error() }}</p>
      }
    </div>
  `,
  styles: [`
    .nx-input-wrapper { display: flex; flex-direction: column; gap: 4px; }
    .nx-input-label { font-size: 11px; font-weight: 500; color: var(--nx-text-secondary); letter-spacing: 0.02em; text-transform: uppercase; }
    .nx-input-field {
      display: flex; align-items: center; gap: 8px;
      padding: 8px 12px; background: var(--nx-surface);
      border: 1px solid var(--nx-border); border-radius: 6px;
      font-family: 'Inter', sans-serif; font-size: 14px;
      color: var(--nx-text);
      transition: border-color 120ms cubic-bezier(0.4,0,0.2,1), box-shadow 120ms cubic-bezier(0.4,0,0.2,1);
      &:focus-within { border-color: var(--nx-accent); box-shadow: 0 0 0 2px var(--nx-accent); }
      &.nx-input-error { border-color: var(--nx-danger); &:focus-within { box-shadow: 0 0 0 2px var(--nx-danger); } }
      &.nx-input-disabled { opacity: 0.5; cursor: not-allowed; }
    }
    .nx-input-control { flex: 1; border: none; background: transparent; font-family: 'Inter', sans-serif; font-size: 14px; color: var(--nx-text); outline: none; &::placeholder { color: var(--nx-text-muted); } &:disabled { cursor: not-allowed; } }
    .nx-input-hint { font-size: 11px; color: var(--nx-text-muted); }
    .nx-input-error-text { font-size: 11px; color: var(--nx-danger); }
    .nx-input-error-icon { color: var(--nx-danger); font-size: 16px; flex-shrink: 0; }
    .nx-input-loading-icon { color: var(--nx-text-muted); font-size: 16px; animation: nx-spin 1s linear infinite; flex-shrink: 0; }
    @keyframes nx-spin { to { transform: rotate(360deg); } }
  `],
})
export class NxInputComponent {
  readonly label = input('');
  readonly type = input<'text' | 'email' | 'password' | 'number' | 'search' | 'tel' | 'url' | 'date'>('text');
  readonly placeholder = input('');
  readonly hint = input('');
  readonly error = input('');
  readonly disabled = input(false);
  readonly readonly = input(false);
  readonly loading = input(false);
  readonly focused = input(false);
  readonly value = input('');
  readonly valueChange = output<string>();
  readonly blurred = output<void>();
  readonly focusedOut = output<void>();

  onInput(value: string): void { this.valueChange.emit(value); }
  onBlur(): void { this.blurred.emit(); }
  onFocus(): void { this.focusedOut.emit(); }
}
