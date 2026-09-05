import { inject } from '@angular/core';
import { CanActivateFn, Router } from '@angular/router';
import { AuthService } from './auth.service';

/**
 * Bloquea el acceso a una ruta si el usuario no está logueado
 * (o si el token venció).|
 */
export const authGuard: CanActivateFn = () => {
    const auth = inject(AuthService);
    const router = inject(Router);

    if (auth.estaLogueado()) {
        return true;
    }
    router.navigate(['/login']);
    return false;
};