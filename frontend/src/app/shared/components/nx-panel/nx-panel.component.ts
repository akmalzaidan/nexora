import { Component, input } from '@angular/core';
import { CommonModule } from '@angular/common';

/**
 * NxPanel — workspace section container.
 */
@Component({
  selector: 'app-nx-panel',
  standalone: true,
  imports: [CommonModule],
  template: `
    <div class="nx-panel" [class.nx-panel-elevated]="elevated()">
      @if (title() || showHeader()) {
        <div class="nx-panel-header">
          <h3 class="nx-panel-title">{{ title() }}</h3>
          <ng-content select="[nx-panel-header-action]"></ng-content>
        </div>
      }
      <div class="nx-panel-body"><ng-content></ng-content></div>
      @if (showFooter()) {
        <div class="nx-panel-footer">
          <ng-content select="[nx-panel-footer]"></ng-content>
        </div>
      }
    </div>
  `,
  styles: [`
    .nx-panel {
      background: var(--nx-surface); border: 1px solid var(--nx-border);
      border-radius: 8px; overflow: hidden;
      display: flex; flex-direction: column;
    }
    .nx-panel-elevated { background: var(--nx-surface-elevated); box-shadow: 0 4px 6px rgba(0,0,0,0.2); }
    .nx-panel-header { display: flex; align-items: center; justify-content: space-between; padding: 12px 16px; border-bottom: 1px solid var(--nx-border-subtle); }
    .nx-panel-title { font-size: 12px; font-weight: 600; color: var(--nx-text); text-transform: uppercase; letter-spacing: 0.04em; margin: 0; }
    .nx-panel-body { padding: 16px; }
    .nx-panel-footer { padding: 12px 16px; border-top: 1px solid var(--nx-border-subtle); background: color-mix(in srgb, var(--nx-bg) 3%, var(--nx-surface)); }
  `],
})
export class NxPanelComponent {
  readonly title = input('');
  readonly showHeader = input(false);
  readonly showFooter = input(false);
  readonly elevated = input(false);
}
