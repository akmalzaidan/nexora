import { Component, input, output, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { IonIcon } from '@ionic/angular';

/**
 * NxSearch — compact enterprise search field.
 *
 * Supports search input with clear button and keyboard shortcut hint.
 */
@Component({
  selector: 'app-nx-search',
  standalone: true,
  imports: [CommonModule, FormsModule, IonIcon],
  template: `
    <div class="nx-search">
      <ion-icon class="nx-search-icon" name="search"></ion-icon>
      <input
        class="nx-search-input"
        [placeholder]="placeholder()"
        [value]="value()"
        (input)="onInput($any($event.target).value)"
        (focus)="focused.set(true)"
        (blur)="focused.set(false)"
      />
      @if (value()) {
        <button class="nx-search-clear" (click)="clear()">
          <ion-icon name="close-circle"></ion-icon>
        </button>
      }
      @if (showShortcut()) {
        <div class="nx-search-shortcut">
          <span class="nx-kbd">Ctrl</span>
          <span class="nx-kbd">K</span>
        </div>
      }
    </div>
  `,
  styles: [`
    .nx-search {
      position: relative; display: flex; align-items: center; gap: 8px;
      padding: 6px 12px; padding-right: 40px;
      background: var(--nx-surface); border: 1px solid var(--nx-border);
      border-radius: 6px; min-width: 200px;
      transition: border-color 120ms cubic-bezier(0.4,0,0.2,1), box-shadow 120ms cubic-bezier(0.4,0,0.2,1);
      &:focus-within { border-color: var(--nx-accent); box-shadow: 0 0 0 2px var(--nx-accent); }
    }
    .nx-search-icon { color: var(--nx-text-muted); font-size: 16px; flex-shrink: 0; }
    .nx-search-input {
      flex: 1; border: none; background: transparent;
      font-family: 'Inter', sans-serif; font-size: 14px;
      color: var(--nx-text); outline: none;
      &::placeholder { color: var(--nx-text-muted); }
    }
    .nx-search-clear {
      background: none; border: none; cursor: pointer;
      color: var(--nx-text-muted); padding: 4px;
      display: flex; align-items: center; justify-content: center;
      border-radius: 4px; font-size: 16px; flex-shrink: 0;
      transition: color 120ms cubic-bezier(0.4,0,0.2,1);
      &:hover { color: var(--nx-text); }
    }
    .nx-search-shortcut {
      position: absolute; right: 10px;
      display: flex; align-items: center; gap: 2px;
      .nx-kbd {
        font-size: 10px; padding: 0 4px; height: 16px;
        background: color-mix(in srgb, var(--nx-surface-elevated) 90%, var(--nx-bg));
        border: 1px solid var(--nx-border); border-radius: 3px;
        font-family: 'JetBrains Mono', monospace;
        color: var(--nx-text-muted);
        display: inline-flex; align-items: center; justify-content: center;
      }
    }
  `],
})
export class NxSearchComponent {
  readonly placeholder = input('Search...');
  readonly value = input<string>('');
  readonly showShortcut = input(true);
  readonly nxSearch = output<string>();
  readonly focused = signal<boolean>(false);

  onInput(value: string): void { this.nxSearch.emit(value); }
  clear(): void { this.nxSearch.emit(''); }
}
