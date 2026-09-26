import { Injectable } from '@angular/core';
import { CommandPaletteService } from './command-palette.service';
import { ThemeService } from './theme.service';

/**
 * AppStateService is the central coordinator for global application state.
 *
 * It wires together the command palette keyboard shortcut,
 * theme toggling, and other cross-cutting concerns.
 */
@Injectable({ providedIn: 'root' })
export class AppStateService {
  private readonly commandPalette = inject(CommandPaletteService);
  private readonly theme = inject(ThemeService);

  constructor() {
    // Bind keyboard shortcut for command palette
    this.bindCommandPaletteShortcut();
  }

  /**
   * Bind Ctrl/Cmd + K to toggle the command palette.
   * Also handle Escape to close it.
   */
  private bindCommandPaletteShortcut(): void {
    document.addEventListener('keydown', (event: KeyboardEvent) => {
      // Ctrl/Cmd + K → toggle command palette
      if ((event.ctrlKey || event.metaKey) && event.key === 'k') {
        event.preventDefault();
        this.commandPalette.toggle();
      }

      // Escape → close command palette if open
      if (event.key === 'Escape' && this.commandPalette.isOpen()) {
        this.commandPalette.close();
      }
    });
  }

  /**
   * Toggle the theme.
   */
  toggleTheme(): void {
    this.theme.toggle();
  }

  /**
   * Cycle the theme mode.
   */
  cycleTheme(): void {
    this.theme.cycle();
  }
}
