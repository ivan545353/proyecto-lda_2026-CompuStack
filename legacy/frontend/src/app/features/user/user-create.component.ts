import { Component, OnInit, inject, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { UserService } from '../../core/user/user.service';
import { Profile } from '../../core/models/user.model';
import { clavesIguales } from './claves-iguales.validator';

const SOLO_LETRAS = /^[A-Za-zÁÉÍÓÚáéíóúÑñ\s\-']+$/;

@Component({
    selector: 'app-user-create',
    standalone: true,
    imports: [ReactiveFormsModule, RouterLink],
    templateUrl: './user-create.component.html'
})
export class UserCreateComponent implements OnInit {
    private fb = inject(FormBuilder);
    private service = inject(UserService);
    private router = inject(Router);

    perfiles = signal<Profile[]>([]);
    error = signal<string | null>(null);
    guardando = signal(false);

    form = this.fb.group({
        apellido: ['', [Validators.required, Validators.minLength(2), Validators.maxLength(50), Validators.pattern(SOLO_LETRAS)]],
        nombres: ['', [Validators.required, Validators.minLength(2), Validators.maxLength(50), Validators.pattern(SOLO_LETRAS)]],
        cuenta: ['', [Validators.required, Validators.minLength(2), Validators.maxLength(50)]],
        perfil_id: [null as number | null, [Validators.required]],
        correo: ['', [Validators.required, Validators.email, Validators.pattern(/^[^\s@]+@[^\s@]+\.[^\s@]+$/)]],
        clave: ['', [Validators.required, Validators.minLength(8)]],
        confirmarClave: ['', [Validators.required]]
    }, { validators: clavesIguales });

    ngOnInit(): void {
        this.service.listProfiles().subscribe({
            next: ps => this.perfiles.set(ps),
            error: () => this.error.set('No se pudieron cargar los perfiles.')
        });
    }

    guardar(): void {
        if (this.form.invalid) {
            this.form.markAllAsTouched();
            return;
        }
        this.error.set(null);
        this.guardando.set(true);

        const v = this.form.getRawValue();
        this.service.save({
            apellido: v.apellido!,
            nombres: v.nombres!,
            cuenta: v.cuenta!,
            perfil_id: v.perfil_id!,
            correo: v.correo!,
            clave: v.clave!
        }).subscribe({
            next: () => this.router.navigate(['/user']),
            error: err => {
                this.error.set(err?.error?.error ?? 'No se pudo crear el usuario.');
                this.guardando.set(false);
            }
        });
    }
}