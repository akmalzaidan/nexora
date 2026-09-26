// ═══════════════════════════════════════════════════════════════════
// NX Table — Enterprise Data Table Foundation
// ═══════════════════════════════════════════════════════════════════

import { Component, input, output, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { NxLoadingStateComponent } from '../nx-loading-state/nx-loading-state.component';
import { NxErrorStateComponent } from '../nx-error-state/nx-error-state.component';
import { NxEmptyStateComponent } from '../nx-empty-state/nx-empty-state.component';
import { NxBadgeComponent } from '../nx-badge/nx-badge.component';

/**
 * NxTable — reusable enterprise data table.
 *
 * Features:
 * - Compact rows
 * - Column hierarchy
 * - Hover/selected states
 * - Status badges
 * - Monospace technical identifiers
 * - Responsive overflow
 * - Empty/loading/error states
 */
@Component({
  selector: 'app-nx-table',
  standalone: true,
  imports: [
    CommonModule,
    NxLoadingStateComponent,
    NxErrorStateComponent,
    NxEmptyStateComponent,
    NxBadgeComponent,
  ],
  template: `
    <div class="nx-table-wrapper">
      @if (loading()) {
        <app-nx-loading-state mode="overlay"></app-nx-loading-state>
      }
      @if (error()) {
        <app-nx-error-state
          [title]="error()"
          [showRetry]="true"
          (nxRetry)="retry.emit()"
        ></app-nx-error-state>
      }
      @if (!loading() && !error() && data().length === 0) {
        <app-nx-empty-state
          icon="document-outline"
          title="No data"
          description="There are no items to display."
        ></app-nx-empty-state>
      }
      @if (!loading() && !error()) {
        <div class="nx-table-container">
          <table class="nx-table">
            <thead>
              <tr>
                @for (column of columns(); track column.key) {
                  <th
                    [class.nx-table-col-resizable]="column.resizable"
                    [style.width]="column.width"
                  >
                    <div class="nx-table-header-content">
                      <span class="nx-table-header-label">{{ column.label }}</span>
                      <ng-content select="[nx-table-header-action]"></ng-content>
                    </div>
                  </th>
                }
              </tr>
            </thead>
            <tbody>
              @for (row of data(); track row.id) {
                <tr
                  [class.nx-table-row-selected]="selectedIds().includes(row.id)"
                  [class.nx-table-row-hover]="hoverable()"
                  (click)="onRowClick(row)"
                  (mouseenter)="hoveredRow.set($index)"
                  (mouseleave)="hoveredRow.set(-1)"
                >
                  @for (column of columns(); track column.key) {
                    <td>
                      <ng-container
                        [ngTemplateOutlet]="cellTemplate"
                        [ngTemplateOutletContext]="{
                          $implicit: row,
                          column: column,
                          index: $index
                        }"
                      ></ng-container>
                    </td>
                  }
                </tr>
              }
            </tbody>
          </table>
        </div>
      }
    </div>

    <ng-template #cellTemplate let-row="row" let-column="column" let-i="index">
      @if (!column.template) {
        <span
          [class.nx-table-mono]="column.mono"
          [class.nx-table-truncate]="column.truncate"
        >
          {{ getCellValue(row, column) }}
        </span>
        @if (column.status) {
          <app-nx-badge [status]="getCellValue(row, column)"></app-nx-badge>
        }
      }
      <ng-content select="[nx-cell]"></ng-content>
    </ng-template>
  `,
  styles: [`
    .nx-table-wrapper {
      background: var(--nx-surface);
      border: 1px solid var(--nx-border);
      border-radius: 8px;
      overflow: hidden;
    }
    .nx-table-container {
      overflow-x: auto;
      -webkit-overflow-scrolling: touch;
    }
    .nx-table {
      width: 100%;
      border-collapse: collapse;
      font-family: 'Inter', sans-serif;
    }
    .nx-table thead {
      background: color-mix(in srgb, var(--nx-surface-elevated) 50%, var(--nx-surface));
      border-bottom: 1px solid var(--nx-border);
    }
    .nx-table th {
      padding: 10px 12px;
      text-align: left;
      font-size: 11px;
      font-weight: 600;
      color: var(--nx-text-secondary);
      text-transform: uppercase;
      letter-spacing: 0.04em;
      white-space: nowrap;
      user-select: none;
    }
    .nx-table-col-resizable { cursor: col-resize; }
    .nx-table-header-content {
      display: flex; align-items: center; justify-content: space-between; gap: 8px;
    }
    .nx-table-header-label { display: block; }
    .nx-table tbody tr {
      border-bottom: 1px solid var(--nx-border-subtle);
      transition: background 80ms ease;
      &:last-child { border-bottom: none; }
    }
    .nx-table-row-hover {
      &:hover { background: color-mix(in srgb, var(--nx-surface-elevated) 40%, var(--nx-surface)); }
    }
    .nx-table-row-selected { background: var(--nx-accent-subtle); }
    .nx-table td {
      padding: 8px 12px;
      font-size: 14px;
      color: var(--nx-text);
      vertical-align: middle;
      transition: background 80ms ease;
    }
    .nx-table-mono {
      font-family: 'JetBrains Mono', 'Fira Code', monospace;
      font-size: 13px;
      letter-spacing: -0.01em;
    }
    .nx-table-truncate {
      display: block;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
      max-width: 200px;
    }
  `],
})
export class NxTableComponent<T extends { id: string | number }> {
  readonly columns = input<NxTableColumn<T>[]>([]);
  readonly data = input<T[]>([]);
  readonly loading = input(false);
  readonly error = input('');
  readonly hoverable = input(true);
  readonly selectedIds = input<(string | number)[]>([]);

  readonly hoveredRow = signal<number>(-1);

  readonly rowClick = output<T>();
  readonly retry = output<void>();

  onRowClick(row: T): void {
    this.rowClick.emit(row);
  }

  getCellValue(row: T, column: NxTableColumn<T>): string | number | null {
    if (column.field) {
      return (row as any)[column.field] ?? null;
    }
    return null;
  }
}

export interface NxTableColumn<T> {
  key: string;
  label: string;
  field?: keyof T | string;
  width?: string;
  resizable?: boolean;
  mono?: boolean;
  truncate?: boolean;
  status?: 'active' | 'inactive' | 'pending' | 'maintenance' | 'success' | 'warning' | 'danger' | 'info';
  template?: any;
}
