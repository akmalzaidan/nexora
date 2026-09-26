import { Component, inject, signal, computed } from '@angular/core';
import { Router, RouterModule } from '@angular/router';
import { NxInputComponent } from '../../shared/components/nx-input/nx-input.component';
import { NxButtonComponent } from '../../shared/components/nx-button/nx-button.component';
import { NxPanelComponent } from '../../shared/components/nx-panel/nx-panel.component';
import { AuthService } from '../../core/services/auth.service';

@Component({
  selector: 'app-register',
  standalone: true,
  imports: [RouterModule, NxInputComponent, NxButtonComponent, NxPanelComponent],
  template: `
    <div class="nx-login-page">
      <div class="nx-login-brand">
        <div class="nx-login-logo">
          <svg viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg">
            <rect width="32" height="32" rx="6" fill="var(--nx-accent)" fill-opacity="0.15"/>
            <path d="M8 8L24 24M24 8L8 24" stroke="var(--nx-accent)" stroke-width="2" stroke-linecap="round"/>
          </svg>
        </div>
        <h1 class="nx-login-title">NEXORA</h1>
        <p class="nx-login-subtitle">Create your account</p>
      </div>

      <app-nx-panel title="Register">
        @if (auth.authError()) {
          <div class="nx-login-error" role="alert">{{ auth.authError() }}</div>
        }
        <app-nx-input
          label="Full Name"
          placeholder="Your full name"
          [value]="name"
          (valueChange)="name = $event"
          [error]="fieldError('name')"
          [disabled]="auth.isLoading()"
        ></app-nx-input>
        <app-nx-input
          label="Email"
          type="email"
          placeholder="you@company.com"
          [value]="email"
          (valueChange)="email = $event"
          [error]="fieldError('email')"
          [disabled]="auth.isLoading()"
          style="margin-top: 12px;"
        ></app-nx-input>
        <app-nx-input
          label="Password"
          type="password"
          placeholder="Min. 8 characters"
          [value]="password"
          (valueChange)="password = $event"
          [error]="fieldError('password')"
          [disabled]="auth.isLoading()"
          style="margin-top: 12px;"
        ></app-nx-input>
        <app-nx-input
          label="Confirm Password"
          type="password"
          placeholder="Confirm your password"
          [value]="confirmPassword"
          (valueChange)="confirmPassword = $event"
          [error]="fieldError('password_confirmation')"
          [disabled]="auth.isLoading()"
          style="margin-top: 12px;"
        ></app-nx-input>
        <app-nx-button
          variant="primary"
          style="width: 100%; margin-top: 16px;"
          [disabled]="auth.isLoading()"
          (nxClick)="register()"
        >
          {{ auth.isLoading() ? 'Creating account…' : 'Create Account' }}
        </app-nx-button>
        <p style="text-align: center; font-size: 13px; color: var(--nx-text-muted); margin-top: 16px;">
          Already have an account? <a routerLink="/login" style="color: var(--nx-accent); text-decoration: none;">Sign in</a>
        </p>
      </app-nx-panel>

      <div class="nx-login-footer">
        <p>NEXORA v1.0.0 · Enterprise Operations Platform</p>
      </div>
    </div>
  `,
  styles: [`
    .nx-login-page {
      min-height: 100vh;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      padding: 24px;
      background: var(--nx-bg);
    }
    .nx-login-brand {
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 12px;
      margin-bottom: 32px;
    }
    .nx-login-logo { width: 48px; height: 48px; }
    .nx-login-logo svg { width: 100%; height: 100%; }
    .nx-login-title {
      font-family: 'Inter', sans-serif;
      font-size: 28px;
      font-weight: 700;
      color: var(--nx-text);
      letter-spacing: 0.06em;
      margin: 0;
    }
    .nx-login-subtitle { font-size: 13px; color: var(--nx-text-muted); margin: 0; }
    .nx-login-error {
      padding: 8px 12px; margin-bottom: 12px; border-radius: 6px;
      font-size: 13px; color: var(--nx-danger);
      background: color-mix(in srgb, var(--nx-danger) 10%, transparent);
      border: 1px solid color-mix(in srgb, var(--nx-danger) 30%, transparent);
    }
    .nx-login-footer {
      position: absolute;
      bottom: 24px;
      p { margin: 0; font-size: 11px; color: var(--nx-text-muted); }
    }
  `],
})
export class RegisterPage {
  readonly auth = inject(AuthService);
  private readonly router = inject(Router);

  name = '';
  email = '';
  password = '';
  confirmPassword = '';

  /** Client-side validation errors (merged with server-side ones). */
  private readonly clientErrors = signal<Record<string, string>>({});

  /** Combined field-level errors: client validation first, then server. */
  readonly fieldErrors = computed(() => ({
    ...this.auth.fieldErrors(),
    ...this.clientErrors(),
  }));

  fieldError(name: string): string {
    return this.fieldErrors()[name] ?? '';
  }

  async register(): Promise<void> {
    const errors: Record<string, string> = {};
    if (!this.name.trim()) errors['name'] = 'Name is required.';
    if (!this.email.trim()) {
      errors['email'] = 'Email is required.';
    } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(this.email.trim())) {
      errors['email'] = 'Enter a valid email address.';
    }
    if (!this.password) {
      errors['password'] = 'Password is required.';
    } else if (this.password.length < 8) {
      errors['password'] = 'Password must be at least 8 characters.';
    }
    if (this.confirmPassword !== this.password) {
      errors['password_confirmation'] = 'Passwords do not match.';
    }
    if (Object.keys(errors).length > 0) {
      this.clientErrors.set(errors);
      this.auth.clearErrors();
      return;
    }
    this.clientErrors.set({});

    const ok = await this.auth.register({
      name: this.name.trim(),
      email: this.email.trim(),
      password: this.password,
      password_confirmation: this.confirmPassword,
    });
    if (ok) {
      this.router.navigateByUrl(this.auth.resolvePostLoginUrl());
    }
  }
}