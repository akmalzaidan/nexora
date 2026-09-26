import { inject } from '@angular/core';
import { CanActivateFn, Router } from '@angular/router';

import { AuthService, isSafeInternalUrl } from '../services/auth.service';
import { StorageService } from '../services/storage.service';

/**
 * Guards the application shell (and therefore every protected module route).
 * Waiting on `ensureInitialized()` restores any persisted session before the
 * decision is made, so a valid stored token is never bounced to `/login`.
 * Unauthenticated visitors are sent to `/login` while their intended route is
 * remembered for after a successful sign-in.
 */
export const authGuard: CanActivateFn = async (_route, state) => {
  const auth = inject(AuthService);
  const storage = inject(StorageService);
  const router = inject(Router);

  await auth.ensureInitialized();

  if (auth.isAuthenticated()) {
    return true;
  }

  if (!state.url.startsWith('/login') && !state.url.startsWith('/register')) {
    if (isSafeInternalUrl(state.url)) {
      storage.set('nx-return-url', state.url);
    }
  }

  return router.createUrlTree(['/login']);
};