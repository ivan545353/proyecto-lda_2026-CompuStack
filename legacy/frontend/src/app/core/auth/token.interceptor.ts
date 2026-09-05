import { HttpInterceptorFn, HttpErrorResponse } from '@angular/common/http';
import { inject } from '@angular/core';
import { Router } from '@angular/router';
import { catchError, throwError } from 'rxjs';
import { AuthService } from './auth.service';

/**
 * Se ejecuta en CADA petición HTTP. Si hay token, lo agrega como
 * "Authorization: Bearer ...". Si el backend responde 401, cierra
 * sesión y manda al login. Es el puente con AuthenticationHandlerMiddleware.
 */
export const tokenInterceptor: HttpInterceptorFn = (req, next) => {
    const auth = inject(AuthService);
    const router = inject(Router);
    const token = auth.getToken();

    const authReq = token
        ? req.clone({ setHeaders: { Authorization: `Bearer ${token}` } })
        : req;

    return next(authReq).pipe(
        catchError((err: HttpErrorResponse) => {
            if (err.status === 401) {
                auth.logout();
                router.navigate(['/login']);
            }
            return throwError(() => err);
        })
    );
};