import { Component, OnInit, inject, signal } from '@angular/core';
import {
    AbstractControl, FormBuilder, ReactiveFormsModule, ValidationErrors, Validators
} from '@angular/forms';
import { UserService } from '../../core/user/user.service';
import { User } from '../../core/models/user.model';

// Valida que la nueva clave y su confirmación coincidan
function nuevasClavesIguales(group: AbstractControl): ValidationErrors | null {
    const n = group.get('newPassword')?.value ?? '';
    const c = group.get('confirmPassword')?.value ?? '';
    if (n === '' && c === '') return null;
    return n === c ? null : { noCoincide: true };
}

@Component({
    selector: 'app-account',
    standalone: true,
    imports: [ReactiveFormsModule],
    templateUrl: './account.component.html'
})
export class AccountComponent implements OnInit {
    private fb = inject(FormBuilder);
    private userService = inject(UserService);

    usuario = signal<User | null>(null);
    error = signal<string | null>(null);
    exito = signal<string | null>(null);
    procesando = signal(false);

    form = this.fb.group({
        currentPassword: ['', [Validators.required]],
        newPassword: ['', [Validators.required, Validators.minLength(8)]],
        confirmPassword: ['', [Validators.required]]
    }, { validators: nuevasClavesIguales });

    ngOnInit(): void {
        this.userService.getCurrent().subscribe({
            next: u => this.usuario.set(u),
            error: err => this.error.set(err?.error?.error ?? 'No se pudieron cargar tus datos.')
        });
    }

    cambiarClave(): void {
        if (this.form.invalid) {
            this.form.markAllAsTouched();
            return;
        }
        this.procesando.set(true);
        this.error.set(null);
        this.exito.set(null);

        const v = this.form.getRawValue();
        this.userService.changePassword({
            currentPassword: v.currentPassword!,
            newPassword: v.newPassword!,
            confirmPassword: v.confirmPassword!
        }).subscribe({
            next: msg => {
                this.exito.set(msg || 'Contraseña actualizada correctamente.');
                this.form.reset();
                this.procesando.set(false);
            },
            error: err => {
                this.error.set(err?.error?.error ?? 'No se pudo cambiar la contraseña.');
                this.procesando.set(false);
            }
        });
    }
}