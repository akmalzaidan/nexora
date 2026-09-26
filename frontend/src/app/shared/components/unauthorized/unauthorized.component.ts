import { Component, inject } from '@angular/core';
import { Router } from '@angular/router';

import { NxButtonComponent } from '../nx-button/nx-button.component';

/**
 * Unauthorized state shown when an authenticated user lacks the permission
 * for a route or operation. Quiet and operational: existing design tokens,
 * no gradients, no illustration, no animation-heavy UI.
 */
@Component({
  selector: 'app-unauthorized',
  standalone: true,
  imports: [NxButtonComponent],
  template: `
    <div class="nx-unauthorized" role="alert">
      <h2 class="nx-unauthorized-title">ACCESS RESTRICTED</h2>
      <p class="nx-unauthorized-message">
        You don't have permission to access this operation.
      </p>
      <app-nx-button variant="secondary" (nxClick)="returnToCommand()">
        Return to Command
      </app-nx-button>
    </div>
  `,
  styles: [`
    .nx-unauthorized {
      min-height: 60vh;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      gap: 12px;
      padding: 32px 24px;
      text-align: center;
    }
    .nx-unauthorized-title {
      margin: 0;
      font-family: 'Inter', sans-serif;
      font-size: 13px;
      font-weight: 600;
      letter-spacing: 0.08em;
      color: var(--nx-text);
    }
    .nx-unauthorized-message {
      margin: 0;
      font-size: 14px;
      line-height: 1.5;
      color: var(--nx-text-muted);
      max-width: 420px;
    }
  `],
})
export class UnauthorizedComponent {
  private readonly router = inject(Router);

  returnToCommand(): void {
    void this.router.navigate(['/home']);
  }
}
