import { Component, computed, input } from '@angular/core';
import { CommonModule } from '@angular/common';

import { ReportDayBucket } from '../../../core/services/report.service';

/**
 * ReportTrend — the backend's gap-filled daily series drawn as plain CSS bars.
 *
 * Constraints that shaped this component:
 *  - no chart library, so the series is a flex row of divs, not a canvas;
 *  - no derived insight, so there is no trend line, no moving average, and no
 *    "up/down" verdict. Only the counts the API returned are shown;
 *  - the values must remain readable without sight, so the figure is labelled
 *    and a full data table sits behind a disclosure.
 */
@Component({
  selector: 'app-report-trend',
  standalone: true,
  imports: [CommonModule],
  template: `
    <figure class="nx-trend">
      <figcaption class="nx-trend-caption">
        <span class="nx-trend-title">{{ title() }}</span>
        <span class="nx-trend-note">{{ note() }}</span>
      </figcaption>

      @if (buckets().length) {
        <div
          class="nx-trend-plot"
          role="img"
          [attr.aria-label]="summary()"
        >
          @for (bucket of buckets(); track bucket.date) {
            <div class="nx-trend-col">
              <span class="nx-trend-value">{{ bucket.count }}</span>
              <span class="nx-trend-track">
                <span class="nx-trend-bar" [style.height.%]="heightFor(bucket.count)"></span>
              </span>
            </div>
          }
        </div>
        <div class="nx-trend-axis" aria-hidden="true">
          <span>{{ firstDate() }}</span>
          <span>{{ lastDate() }}</span>
        </div>

        <details class="nx-trend-table">
          <summary>View as table</summary>
          <table class="nx-trend-data">
            <thead>
              <tr>
                <th scope="col">Date</th>
                <th scope="col">Created</th>
              </tr>
            </thead>
            <tbody>
              @for (bucket of buckets(); track bucket.date) {
                <tr>
                  <th scope="row">{{ bucket.date }}</th>
                  <td>{{ bucket.count.toLocaleString() }}</td>
                </tr>
              }
            </tbody>
          </table>
        </details>
      } @else {
        <p class="nx-trend-empty">{{ emptyText() }}</p>
      }
    </figure>
  `,
  styleUrl: './report-trend.component.scss',
})
export class ReportTrendComponent {
  readonly title = input('');
  readonly buckets = input<ReportDayBucket[]>([]);
  /** Extra context printed next to the title, e.g. the number of days. */
  readonly note = input('');
  readonly emptyText = input('No daily activity in this period.');

  /** Highest single day, the scale every bar is drawn against. */
  private readonly peak = computed(() =>
    this.buckets().reduce((highest, bucket) => (bucket.count > highest ? bucket.count : highest), 0),
  );

  heightFor(count: number): number {
    const peak = this.peak();
    if (peak <= 0) {
      return 0;
    }
    // A non-zero day always keeps a sliver of height so "1" stays visible.
    return Math.max(4, (count / peak) * 100);
  }

  firstDate(): string {
    return this.buckets()[0]?.date ?? '';
  }

  lastDate(): string {
    const buckets = this.buckets();
    return buckets.length ? buckets[buckets.length - 1].date : '';
  }

  /**
   * Screen-reader description of the series.
   *
   * Deliberately structural: the number of days and the covered range. No
   * average, total, peak, or direction is announced, because none of those are
   * supplied by the API and the workspace never computes its own metrics.
   */
  summary(): string {
    const buckets = this.buckets();
    if (!buckets.length) {
      return this.emptyText();
    }
    return `${this.title()}: ${buckets.length} daily values from ${this.firstDate()} to ${this.lastDate()}. Every value is listed in the table below.`;
  }
}
