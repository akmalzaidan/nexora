import { inject } from '@angular/core';
import { CanActivateFn, Router } from '@angular/router';

import { AuthService } from '../services/auth.service';

/**
 * Guards public routes (`/login`, `/register`). Authenticated visitors are
 * redirected to `/home`; guests may proceed.
 */
export const guestGuard: CanActivateFn = async () => {
  const auth = inject(AuthService);
  const router = inject(Router);

  await auth.ensureInitialized();

  if (auth.isAuthenticated()) {
    return router.createUrlTree(['/home']);
  }

  return true;
};