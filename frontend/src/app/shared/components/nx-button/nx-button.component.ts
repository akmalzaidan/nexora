import { Component, input, output } from '@angular/core';
import { CommonModule } from '@angular/common';

/**
 * NxButton — reusable button primitive.
 *
 * Variants: primary, secondary, ghost, danger, icon
 * Sizes: sm, lg
 */
@Component({
  selector: 'app-nx-button',
  standalone: true,
  imports: [CommonModule],
  template: `
    <button
      [class]="buttonClasses()"
      [type]="type()"
      [disabled]="disabled()"
      [attr.aria-label]="ariaLabel()"
      (click)="onClick($event)"
    >
      <ng-content></ng-content>
    </button>
  `,
  styles: [`
    .nx-button {
      display: inline-flex; align-items: center; justify-content: center; gap: 8px;
      padding: 8px 16px; border: 1px solid transparent; border-radius: 6px;
      font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
      font-size: 14px; font-weight: 500; line-height: 1;
      cursor: pointer; transition: all 200ms cubic-bezier(0.4,0,0.2,1);
      white-space: nowrap; user-select: none;
      &:disabled { opacity: 0.4; cursor: not-allowed; pointer-events: none; }
      &:focus-visible { outline: none; box-shadow: 0 0 0 2px var(--nx-accent); }
      &:active:not(:disabled) { transform: scale(0.98); }
    }
    .nx-button-sm { padding: 4px 12px; font-size: 11px; }
    .nx-button-lg { padding: 10px 24px; font-size: 16px; }
    .nx-button-icon { padding: 8px; aspect-ratio: 1; }
    .nx-button-primary { background: var(--nx-accent); color: color-mix(in srgb, var(--nx-bg) 85%, black); border-color: var(--nx-accent); &:hover:not(:disabled) { filter: brightness(1.1); } }
    .nx-button-secondary { background: color-mix(in srgb, var(--nx-surface) 80%, var(--nx-bg)); color: var(--nx-text); border-color: var(--nx-border); &:hover:not(:disabled) { background: color-mix(in srgb, var(--nx-surface-elevated) 90%, var(--nx-bg)); border-color: var(--nx-border-strong); } }
    .nx-button-ghost { background: transparent; color: var(--nx-text-secondary); border-color: transparent; &:hover:not(:disabled) { background: color-mix(in srgb, var(--nx-surface) 60%, transparent); color: var(--nx-text); } }
    .nx-button-danger { background: var(--nx-danger); color: #fff; border-color: var(--nx-danger); &:hover:not(:disabled) { filter: brightness(1.1); } }
  `],
})
export class NxButtonComponent {
  readonly variant = input<'primary' | 'secondary' | 'ghost' | 'danger' | 'icon'>('primary');
  readonly size = input<'sm' | 'md' | 'lg'>('md');
  readonly type = input<'button' | 'submit' | 'reset'>('button');
  readonly disabled = input(false);
  readonly ariaLabel = input('');

  readonly nxClick = output<MouseEvent>();

  buttonClasses = () => {
    const v = this.variant();
    const s = this.size();
    const classes = ['nx-button'];
    if (v === 'icon') classes.push('nx-button-icon');
    else classes.push(`nx-button-${v}`);
    if (s !== 'md') classes.push(`nx-button-${s}`);
    return classes.join(' ');
  };

  onClick(event: MouseEvent): void {
    if (!this.disabled()) {
      this.nxClick.emit(event);
    }
  }
}
