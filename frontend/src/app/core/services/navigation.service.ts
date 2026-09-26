import { Injectable, signal, computed, inject } from '@angular/core';
import { Router, NavigationEnd } from '@angular/router';
import { filter, pairwise } from 'rxjs/operators';

/**
 * NavigationService tracks the current route and navigation history
 * for the NEXORA application shell.
 */
@Injectable({ providedIn: 'root' })
export class NavigationService {
  readonly router = inject(Router);

  /** Currently active route path (without query params). */
  readonly currentPath = signal<string>('/home');

  /** Previous route path. */
  readonly previousPath = signal<string>('/home');

  /** Whether the current route is a top-level page. */
  readonly isTopLevelPage = computed(() => {
    const path = this.currentPath();
    return path === '/home' || path === '/assets' || path === '/inventory' ||
           path === '/requests' || path === '/maintenance' ||
           path === '/people' || path === '/locations' ||
           path === '/settings' || path === '/profile';
  });

  constructor() {
    this.router.events.pipe(
      filter(event => event instanceof NavigationEnd),
      pairwise(),
    ).subscribe(([prev, curr]: [NavigationEnd, NavigationEnd]) => {
      this.previousPath.set(prev.urlAfterRedirects);
      this.currentPath.set(curr.urlAfterRedirects);
    });
  }

  /** Navigate and update current path signal. */
  navigate(path: string): void {
    this.router.navigate([path]);
  }

  /** Navigate back to the previous route. */
  back(): void {
    this.router.navigate([this.previousPath()]);
  }

  /** Whether we can go back. */
  canGoBack(): boolean {
    return this.previousPath() !== this.currentPath();
  }
}
