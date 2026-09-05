import { Component, inject, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { Router } from '@angular/router';
import { AuthService } from '../../core/auth/auth.service';

@Component({
    selector: 'app-login',
    standalone: true,
    imports: [ReactiveFormsModule],
    templateUrl: './login.component.html',
    styleUrl: './login.component.css'
})
export class LoginComponent {
    private fb = inject(FormBuilder);
    private auth = inject(AuthService);
    private router = inject(Router);

    // Estado de la pantalla como signals (zoneless-friendly)
    error = signal<string | null>(null);
    cargando = signal(false);

    // Formulario reactivo. 'usuario' replica el campo del login original.
    form = this.fb.group({
        usuario: ['', [Validators.required, Validators.minLength(2), Validators.maxLength(50)]],
        contrasena: ['', [Validators.required]]
    });

    ingresar(): void {
        if (this.form.invalid) {
            this.form.markAllAsTouched();
            return;
        }

        this.error.set(null);
        this.cargando.set(true);

        const { usuario, contrasena } = this.form.getRawValue();

        this.auth.login(usuario!, contrasena!).subscribe({
            next: () => this.router.navigate(['/home']),
            error: (err) => {
                // El backend manda el detalle en err.error.error (envelope JSON con 401)
                this.error.set(err?.error?.error ?? err?.message ?? 'No se pudo iniciar sesión.');
                this.cargando.set(false);
            }
        });
    }
}