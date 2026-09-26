import { inject } from '@angular/core';
import { CanActivateFn, Router, UrlTree } from '@angular/router';

import { AuthorizationService } from '../services/authorization.service';
import { authGuard } from './auth.guard';

/**
 * Route-level permission guard. Delegates the authentication decision to the
 * existing authGuard (unauthenticated visitors get exactly the Phase 11
 * behaviour, including return-URL handling), then checks the route's
 * `data.permission` against AuthorizationService. Denials land on the
 * unauthorized state inside the authenticated shell.
 */
export const permissionGuard: CanActivateFn = async (route, state) => {
  const authorization = inject(AuthorizationService);
  const router = inject(Router);

  const authResult = await authGuard(route, state);
  if (authResult !== true) {
    return authResult as UrlTree;
  }

  const permission = route.data?.['permission'];
  if (typeof permission === 'string' && !authorization.hasPermission(permission)) {
    return router.createUrlTree(['/unauthorized']);
  }

  return true;
};
