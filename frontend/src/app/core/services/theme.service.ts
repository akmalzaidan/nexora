import { Injectable, signal, effect, inject } from '@angular/core';
import { Router } from '@angular/router';

export type ThemeMode = 'dark' | 'light' | 'system';

/**
 * ThemeService manages the NEXORA theme system.
 *
 * Supports three modes:
 * - dark: force dark theme
 * - light: force light theme
 * - system: follow OS preference, update on change
 *
 * Preference is persisted to localStorage.
 */
@Injectable({ providedIn: 'root' })
export class ThemeService {
  private readonly storageKey = 'nx-theme-preference';

  readonly mode = signal<ThemeMode>(this.loadStoredMode());
  readonly isDark = signal(false);

  private readonly systemDarkQuery = window.matchMedia('(prefers-color-scheme: dark)');

  constructor() {
    // Apply initial theme
    this.applyTheme();

    // Sync dark state when mode changes
    effect(() => {
      const m = this.mode();
      if (m === 'system') {
        this.isDark.set(this.systemDarkQuery.matches);
      } else {
        this.isDark.set(m === 'dark');
      }
    });

    // Listen for system preference changes when in system mode
    this.systemDarkQuery.addEventListener('change', () => {
      if (this.mode() === 'system') {
        this.applyTheme();
      }
    });
  }

  /** Set theme mode and persist. */
  setMode(mode: ThemeMode): void {
    this.mode.set(mode);
    localStorage.setItem(this.storageKey, mode);
    this.applyTheme();
  }

  /** Toggle between dark and light (ignores system mode). */
  toggle(): void {
    const current = this.mode();
    if (current === 'dark') {
      this.setMode('light');
    } else {
      this.setMode('dark');
    }
  }

  /** Cycle through dark → light → system. */
  cycle(): void {
    const current = this.mode();
    if (current === 'dark') {
      this.setMode('light');
    } else if (current === 'light') {
      this.setMode('system');
    } else {
      this.setMode('dark');
    }
  }

  /**
   * Apply the current theme to the document root.
   * Sets the `data-theme` attribute and updates dark state.
   */
  private applyTheme(): void {
    const root = document.documentElement;
    const m = this.mode();

    if (m === 'system') {
      const systemDark = this.systemDarkQuery.matches;
      root.setAttribute('data-theme', systemDark ? 'dark' : 'light');
      this.isDark.set(systemDark);
    } else {
      root.setAttribute('data-theme', m);
      this.isDark.set(m === 'dark');
    }
  }

  /** Load stored preference, falling back to system. */
  private loadStoredMode(): ThemeMode {
    const stored = localStorage.getItem(this.storageKey);
    if (stored === 'dark' || stored === 'light' || stored === 'system') {
      return stored;
    }
    return 'dark'; // NEXORA default: dark workspace
  }
}
