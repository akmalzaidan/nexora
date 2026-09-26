import { Component, computed, input } from '@angular/core';
import { CommonModule } from '@angular/common';

/** One row of a report breakdown. Values come straight from the API. */
export interface ReportBarRow {
  /** Stable identity for tracking, never shown when it duplicates the label. */
  key: string;
  /** Human label rendered in the table (already humanized by the page). */
  label: string;
  /** Raw count from the API. */
  count: number;
  /** Optional badge status token from the existing status system. */
  status?: string;
}

/**
 * ReportBarList — a compact breakdown rendered as a real table whose count
 * cell carries a proportional bar.
 *
 * A table (not a canvas chart) is deliberate: the numbers are always readable,
 * screen-reader friendly, and selectable without a parallel hidden summary.
 * The bar is a visual aid sized from the largest value in the list, so it never
 * invents a proportion the data does not support.
 */
@Component({
  selector: 'app-report-bar-list',
  standalone: true,
  imports: [CommonModule],
  template: `
    <table class="nx-bl">
      <caption class="nx-sr-only">{{ title() }}</caption>
      <thead>
        <tr>
          <th scope="col" class="nx-bl-label">{{ labelHeading() }}</th>
          <th scope="col" class="nx-bl-count">{{ countHeading() }}</th>
        </tr>
      </thead>
      <tbody>
        @for (row of rows(); track row.key) {
          <tr>
            <th scope="row" class="nx-bl-label">
              @if (row.status) {
                <span class="nx-bl-status" [attr.data-status]="row.status">{{ row.label }}</span>
              } @else {
                {{ row.label }}
              }
            </th>
            <td class="nx-bl-count">
              <span class="nx-bl-number">{{ row.count.toLocaleString() }}</span>
              <span class="nx-bl-track" aria-hidden="true">
                <span class="nx-bl-bar" [style.width.%]="widthFor(row.count)"></span>
              </span>
            </td>
          </tr>
        } @empty {
          <tr>
            <td class="nx-bl-empty" [attr.colspan]="2">{{ emptyText() }}</td>
          </tr>
        }
      </tbody>
    </table>
  `,
  styleUrl: './report-bar-list.component.scss',
})
export class ReportBarListComponent {
  readonly title = input('');
  readonly rows = input<ReportBarRow[]>([]);
  readonly labelHeading = input('Breakdown');
  readonly countHeading = input('Count');
  readonly emptyText = input('No data available');

  /** Largest count in the list; the scale every bar is drawn against. */
  private readonly max = computed(() =>
    this.rows().reduce((highest, row) => (row.count > highest ? row.count : highest), 0),
  );

  widthFor(count: number): number {
    const max = this.max();
    return max > 0 ? (count / max) * 100 : 0;
  }
}
