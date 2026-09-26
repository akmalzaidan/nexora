import { Injectable } from '@angular/core';

/**
 * StorageService provides a thin wrapper around localStorage
 * with JSON serialization for the NEXORA frontend.
 */
@Injectable({ providedIn: 'root' })
export class StorageService {
  /**
   * Get a stored value by key.
   * Returns null if the key does not exist.
   */
  get<T>(key: string): T | null {
    try {
      const raw = localStorage.getItem(key);
      if (raw === null) return null;
      return JSON.parse(raw) as T;
    } catch {
      return null;
    }
  }

  /**
   * Set a value by key, serialized as JSON.
   */
  set<T>(key: string, value: T): void {
    try {
      localStorage.setItem(key, JSON.stringify(value));
    } catch {
      // Quota exceeded or other storage error — silently ignore
    }
  }

  /**
   * Remove a key from storage.
   */
  remove(key: string): void {
    try {
      localStorage.removeItem(key);
    } catch {
      // Ignore
    }
  }

  /**
   * Clear all NEXORA-related keys from storage.
   * Only removes keys with the `nx-` prefix to avoid
   * clearing third-party data.
   */
  clearNexoraKeys(): void {
    const keysToRemove: string[] = [];
    for (let i = 0; i < localStorage.length; i++) {
      const key = localStorage.key(i);
      if (key?.startsWith('nx-')) {
        keysToRemove.push(key);
      }
    }
    keysToRemove.forEach(k => localStorage.removeItem(k));
  }
}
