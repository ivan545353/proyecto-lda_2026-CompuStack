import { Component, inject } from '@angular/core';
import { Router, RouterLink, RouterLinkActive, RouterOutlet } from '@angular/router';
import { AuthService } from '../../core/auth/auth.service';

/**
 * Layout maestro: reemplaza al viejo template.php + menu.php.
 * Contiene el navbar y el <router-outlet> donde se renderizan
 * las pantallas protegidas (home, category, item, etc.).
 */
@Component({
    selector: 'app-layout',
    standalone: true,
    imports: [RouterOutlet, RouterLink, RouterLinkActive],
    templateUrl: './layout.component.html',
    styleUrl: './layout.component.css'
})
export class LayoutComponent {
    auth = inject(AuthService);
    private router = inject(Router);

    cerrarSesion(): void {
        this.auth.logout();
        this.router.navigate(['/login']);
    }
}