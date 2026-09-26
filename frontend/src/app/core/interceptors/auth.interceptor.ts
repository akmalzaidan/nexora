import { HttpErrorResponse, HttpInterceptorFn } from '@angular/common/http';
import { inject } from '@angular/core';
import { catchError, throwError } from 'rxjs';

import { AuthService } from '../services/auth.service';

/**
 * Endpoints that intentionally operate without an authenticated session.
 * A 401 from these is a login/registration failure, handled by the page —
 * never a reason to wipe the session and bounce to `/login`.
 */
const PUBLIC_AUTH_ENDPOINTS = ['/login', '/register'];

/**
 * Attaches `Authorization: Bearer <token>` to API requests when a token is
 * present, and centralizes 401 handling. Requests without a token are sent
 * untouched (no empty Authorization header).
 */
export const authInterceptor: HttpInterceptorFn = (req, next) => {
  const auth = inject(AuthService);
  const isApiRequest = req.url.includes('/api/');

  let outgoing = req;
  if (isApiRequest) {
    const token = auth.token();
    if (token) {
      outgoing = req.clone({
        setHeaders: { Authorization: `Bearer ${token}` },
      });
    }
  }

  return next(outgoing).pipe(
    catchError((error: HttpErrorResponse) => {
      if (
        error.status === 401 &&
        isApiRequest &&
        !PUBLIC_AUTH_ENDPOINTS.some(endpoint => req.url.includes(endpoint))
      ) {
        auth.handleUnauthorized();
      }
      return throwError(() => error);
    }),
  );
};