import { Component, input, computed } from '@angular/core';
import { CommonModule } from '@angular/common';

/**
 * NxBadge — reusable status badge primitive.
 *
 * Statuses: active, inactive, pending, maintenance, success, warning, danger, info
 */
@Component({
  selector: 'app-nx-badge',
  standalone: true,
  imports: [CommonModule],
  template: `
    <span
      [class]="badgeClasses()"
      [attr.aria-label]="ariaLabel()"
    >
      <ng-content></ng-content>
    </span>
  `,
  styles: [`
    .nx-badge {
      display: inline-flex; align-items: center; gap: 4px;
      padding: 2px 8px; border-radius: 9999px;
      font-size: 11px; font-weight: 500; line-height: 1;
      white-space: nowrap; letter-spacing: 0.01em; text-transform: uppercase;
    }
    .nx-badge-success { background: color-mix(in srgb, var(--nx-success) 15%, transparent); color: var(--nx-success); border: 1px solid color-mix(in srgb, var(--nx-success) 25%, transparent); }
    .nx-badge-warning { background: color-mix(in srgb, var(--nx-warning) 15%, transparent); color: var(--nx-warning); border: 1px solid color-mix(in srgb, var(--nx-warning) 25%, transparent); }
    .nx-badge-danger { background: color-mix(in srgb, var(--nx-danger) 15%, transparent); color: var(--nx-danger); border: 1px solid color-mix(in srgb, var(--nx-danger) 25%, transparent); }
    .nx-badge-info { background: color-mix(in srgb, var(--nx-info) 15%, transparent); color: var(--nx-info); border: 1px solid color-mix(in srgb, var(--nx-info) 25%, transparent); }
    .nx-badge-neutral { background: color-mix(in srgb, var(--nx-neutral) 12%, transparent); color: var(--nx-neutral); border: 1px solid color-mix(in srgb, var(--nx-neutral) 20%, transparent); }
    .nx-badge-active { background: color-mix(in srgb, var(--nx-success) 15%, transparent); color: var(--nx-success); border: 1px solid color-mix(in srgb, var(--nx-success) 25%, transparent); }
    .nx-badge-inactive { background: color-mix(in srgb, var(--nx-neutral) 12%, transparent); color: var(--nx-neutral); border: 1px solid color-mix(in srgb, var(--nx-neutral) 20%, transparent); }
    .nx-badge-pending { background: color-mix(in srgb, var(--nx-warning) 15%, transparent); color: var(--nx-warning); border: 1px solid color-mix(in srgb, var(--nx-warning) 25%, transparent); }
    .nx-badge-maintenance { background: color-mix(in srgb, var(--nx-info) 15%, transparent); color: var(--nx-info); border: 1px solid color-mix(in srgb, var(--nx-info) 25%, transparent); }
  `],
})
export class NxBadgeComponent {
  readonly status = input<'active' | 'inactive' | 'pending' | 'maintenance' | 'success' | 'warning' | 'danger' | 'info' | 'neutral'>('neutral');

  readonly ariaLabel = input('');

  readonly badgeClasses = computed(() => {
    const s = this.status();
    return `nx-badge nx-badge-${s}`;
  });
}
