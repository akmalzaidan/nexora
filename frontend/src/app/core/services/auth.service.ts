import { Injectable, inject, signal, computed } from '@angular/core';
import { HttpErrorResponse } from '@angular/common/http';
import { Router } from '@angular/router';
import { firstValueFrom } from 'rxjs';

import { StorageService } from './storage.service';
import { ApiService, ApiResponse } from './api.service';

/**
 * Slim, safe view of the authenticated user as exposed by the backend
 * `UserResource` (never contains password, tokens, or internal fields).
 */
export interface NexoraRole {
  id: number;
  name: string;
  slug: string;
}

export interface NexoraDepartment {
  id: number;
  name: string;
  code: string;
}

export interface NexoraUser {
  id: number;
  name: string;
  email: string;
  role: NexoraRole | null;
  department: NexoraDepartment | null;
  is_active: boolean;
  created_at: string | null;
}

export interface LoginCredentials {
  email: string;
  password: string;
}

export interface RegisterPayload {
  name: string;
  email: string;
  password: string;
  password_confirmation: string;
}

interface AuthData {
  user: NexoraUser;
  token: string;
}

const TOKEN_KEY = 'nx-token';
const USER_KEY = 'nx-user';
const RETURN_URL_KEY = 'nx-return-url';

/**
 * A return URL is safe to follow only when it is an internal application
 * path (starts with a single "/"). This blocks absolute external URLs and
 * protocol-relative URLs such as "//evil.example".
 */
export function isSafeInternalUrl(value: string | null | undefined): value is string {
  return typeof value === 'string' && value.startsWith('/') && !value.startsWith('//');
}

/**
 * AuthService is the single source of truth for the NEXORA authentication
 * session: bearer token, authenticated user, startup restoration, and the
 * login/register/logout flows against the Laravel API.
 */
@Injectable({ providedIn: 'root' })
export class AuthService {
  private readonly api = inject(ApiService);
  private readonly storage = inject(StorageService);
  private readonly router = inject(Router);

  /** Currently authenticated user, or null when signed out. */
  readonly user = signal<NexoraUser | null>(null);

  /** Sanctum bearer token, or null when signed out. */
  readonly token = signal<string | null>(null);

  /** True while login/register/startup restoration is in flight. */
  readonly isLoading = signal(false);

  /** True once the persisted session has been restored or rejected once. */
  readonly isInitialized = signal(false);

  /** Last user-friendly authentication error message, or null. */
  readonly authError = signal<string | null>(null);

  /** Field-level validation errors (keyed by field name). */
  readonly fieldErrors = signal<Record<string, string>>({});

  /** A user is authenticated while a token is present. */
  readonly isAuthenticated = computed(() => this.token() !== null);

  /** Authenticated user's role slug, or null. */
  readonly userRole = computed(() => this.user()?.role?.slug ?? null);

  /** Authenticated user's display role name, or null. */
  readonly roleName = computed(() => this.user()?.role?.name ?? null);

  /** Authenticated user's department name, or null. */
  readonly departmentName = computed(() => this.user()?.department?.name ?? null);

  private initPromise: Promise<void> | null = null;

  /**
   * Restore a persisted session exactly once. Idempotent and safe to call
   * from guards and startup code. When a stored token exists, `/auth/me` is
   * verified; an invalid session is cleared so the app stays unauthenticated.
   */
  ensureInitialized(): Promise<void> {
    if (!this.initPromise) {
      this.initPromise = this.initialize();
    }
    return this.initPromise;
  }

  private async initialize(): Promise<void> {
    if (this.isInitialized()) return;

    const storedToken = this.storage.get<string>(TOKEN_KEY);

    if (!storedToken) {
      this.isInitialized.set(true);
      return;
    }

    this.token.set(storedToken);

    const storedUser = this.storage.get<NexoraUser>(USER_KEY);
    if (storedUser) {
      this.user.set(storedUser);
    }

    this.isLoading.set(true);
    try {
      await this.loadCurrentUser();
    } catch {
      // Any failure (invalid token, revoked session, network outage) means the
      // persisted session cannot be trusted — clear it and stay unauthenticated
      // rather than trapping the user in a broken authenticated UI.
      this.clearSession();
    } finally {
      this.isLoading.set(false);
      this.isInitialized.set(true);
    }
  }

  /**
   * Log in with the public `/auth/login` endpoint.
   * Stores the Sanctum token + user on success. Returns true on success;
   * on failure maps the backend response into `authError`/`fieldErrors`.
   */
  async login(credentials: LoginCredentials): Promise<boolean> {
    this.clearErrors();
    this.isLoading.set(true);
    try {
      const response = await firstValueFrom(
        this.api.post<AuthData>('/auth/login', {
          email: credentials.email.trim(),
          password: credentials.password,
        }),
      );
      this.setSession(response.data);
      return true;
    } catch (error) {
      this.applyHttpError(error);
      return false;
    } finally {
      this.isLoading.set(false);
    }
  }

  /**
   * Register with the public `/auth/register` endpoint. The Laravel API
   * returns an authenticated session (user + token) on success, exactly like
   * login, so the session is established immediately.
   */
  async register(payload: RegisterPayload): Promise<boolean> {
    this.clearErrors();
    this.isLoading.set(true);
    try {
      const response = await firstValueFrom(
        this.api.post<AuthData>('/auth/register', payload),
      );
      this.setSession(response.data);
      return true;
    } catch (error) {
      this.applyHttpError(error);
      return false;
    } finally {
      this.isLoading.set(false);
    }
  }

  /**
   * Refresh the current user from `/auth/me`. Throws on failure so callers
   * (startup restoration) can decide how to handle it.
   */
  async loadCurrentUser(): Promise<NexoraUser> {
    const response = await firstValueFrom(this.api.get<{ user: NexoraUser }>('/auth/me'));
    const { user } = response.data;
    this.user.set(user);
    this.storage.set(USER_KEY, user);
    return user;
  }

  /**
   * Log out through `/auth/logout`. Always clears the local session,
   * even when the server call fails, and redirects to `/login`.
   */
  async logout(): Promise<void> {
    try {
      if (this.token()) {
        await firstValueFrom(this.api.post<null>('/auth/logout', {}));
      }
    } catch {
      // Token may already be invalid — there is still safe local cleanup below.
    } finally {
      this.clearSession();
      this.storage.remove(RETURN_URL_KEY);
      this.router.navigate(['/login']);
    }
  }

  /**
   * A protected request returned 401: discard the invalid session and send the
   * user back to `/login`, remembering where they were heading. Never triggers
   * a redirect loop while already on a public page.
   */
  handleUnauthorized(): void {
    const current = this.router.url;
    this.clearSession();
    if (current === '/login' || current === '/register') return;
    if (isSafeInternalUrl(current) && current !== '/') {
      this.storage.set(RETURN_URL_KEY, current);
    }
    this.router.navigate(['/login']);
  }

  /**
   * Resolve the post-login destination from a remembered return URL.
   * Falls back to `/home`. Always consumes (removes) the stored URL.
   */
  resolvePostLoginUrl(): string {
    const raw = this.storage.get<string>(RETURN_URL_KEY);
    this.storage.remove(RETURN_URL_KEY);
    if (isSafeInternalUrl(raw) && raw !== '/login' && raw !== '/register') {
      return raw;
    }
    return '/home';
  }

  /** Persist the authenticated session (token + user) and update signals. */
  private setSession(data: AuthData): void {
    this.token.set(data.token);
    this.user.set(data.user);
    this.storage.set(TOKEN_KEY, data.token);
    this.storage.set(USER_KEY, data.user);
  }

  /** Clear the session entirely — both in-memory signals and persisted keys. */
  private clearSession(): void {
    this.token.set(null);
    this.user.set(null);
    this.storage.remove(TOKEN_KEY);
    this.storage.remove(USER_KEY);
  }

  /** Clear the last auth error and any field-level validation errors. */
  clearErrors(): void {
    this.authError.set(null);
    this.fieldErrors.set({});
  }

  private applyHttpError(error: unknown): void {
    const info = mapAuthError(error);
    this.authError.set(info.message);
    this.fieldErrors.set(info.fieldErrors);
  }
}

/** User-friendly message for a single backend error scenario. */
export interface AuthErrorInfo {
  message: string;
  fieldErrors: Record<string, string>;
}

/**
 * Translate an HTTP failure from the auth endpoints into safe, user-facing
 * text and (for 422 validation) per-field messages. Raw backend internals are
 * never surfaced.
 */
export function mapAuthError(error: unknown): AuthErrorInfo {
  const fieldErrors: Record<string, string> = {};

  if (error instanceof HttpErrorResponse) {
    const body = error.error as { message?: string; errors?: Record<string, string[]> } | null;

    if (error.status === 422 && body?.errors) {
      for (const [field, messages] of Object.entries(body.errors)) {
        if (messages?.length) {
          fieldErrors[field] = messages[0];
        }
      }
      return { message: 'Please check the highlighted fields.', fieldErrors };
    }

    const messages: Record<number, string> = {
      401: 'Invalid email or password.',
      403: 'This action is unauthorized.',
      429: 'Too many attempts. Please try again later.',
    };

    if (error.status in messages) {
      return { message: messages[error.status], fieldErrors };
    }

    if (error.status === 0) {
      return { message: 'Unable to connect to the server.', fieldErrors };
    }

    if (error.status >= 500) {
      return { message: 'Something went wrong. Please try again.', fieldErrors };
    }

    // Fall back to the backend-provided message for anything else.
    const backendMessage = body?.message;
    if (backendMessage) {
      return { message: backendMessage, fieldErrors };
    }
  }

  return { message: 'Something went wrong. Please try again.', fieldErrors };
}