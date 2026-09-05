import { Component, OnInit, inject, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { UserService } from '../../core/user/user.service';
import { Profile, User } from '../../core/models/user.model';
import { clavesIguales } from './claves-iguales.validator';

const SOLO_LETRAS = /^[A-Za-zÁÉÍÓÚáéíóúÑñ\s\-']+$/;

@Component({
    selector: 'app-user-edit',
    standalone: true,
    imports: [ReactiveFormsModule, RouterLink],
    templateUrl: './user-edit.component.html'
})
export class UserEditComponent implements OnInit {
    private fb = inject(FormBuilder);
    private service = inject(UserService);
    private router = inject(Router);
    private route = inject(ActivatedRoute);

    private id = Number(this.route.snapshot.paramMap.get('id'));
    // Guardamos el usuario cargado para conservar campos que el form no muestra
    private original: User | null = null;

    perfiles = signal<Profile[]>([]);
    editando = signal(false);
    procesando = signal(false);
    error = signal<string | null>(null);

    form = this.fb.group({
        apellido: ['', [Validators.required, Validators.minLength(2), Validators.maxLength(50), Validators.pattern(SOLO_LETRAS)]],
        nombres: ['', [Validators.required, Validators.minLength(2), Validators.maxLength(50), Validators.pattern(SOLO_LETRAS)]],
        cuenta: ['', [Validators.required, Validators.minLength(2), Validators.maxLength(50)]],
        perfil_id: [null as number | null, [Validators.required]],
        correo: ['', [Validators.required, Validators.email, Validators.pattern(/^[^\s@]+@[^\s@]+\.[^\s@]+$/)]],
        estado: [1, [Validators.required]],
        // En edición la clave es opcional: vacía = no se cambia
        clave: ['', [Validators.minLength(8)]],
        confirmarClave: ['']
    }, { validators: clavesIguales });

    ngOnInit(): void {
        this.service.listProfiles().subscribe({
            next: ps => this.perfiles.set(ps),
            error: () => this.error.set('No se pudieron cargar los perfiles.')
        });

        this.service.load(this.id).subscribe({
            next: u => {
                this.original = u;
                this.form.patchValue({
                    apellido: u.apellido, nombres: u.nombres, cuenta: u.cuenta,
                    perfil_id: u.perfil_id, correo: u.correo, estado: u.estado
                });
                this.form.disable();
            },
            error: err => this.error.set(err?.error?.error ?? 'No se encontró el usuario.')
        });
    }

    habilitarEdicion(): void {
        this.editando.set(true);
        this.form.enable();
    }

    cancelar(): void {
        this.editando.set(false);
        this.error.set(null);
        if (this.original) {
            const u = this.original;
            this.form.patchValue({
                apellido: u.apellido, nombres: u.nombres, cuenta: u.cuenta,
                perfil_id: u.perfil_id, correo: u.correo, estado: u.estado,
                clave: '', confirmarClave: ''
            });
        }
        this.form.disable();
    }

    actualizar(): void {
        if (this.form.invalid) {
            this.form.markAllAsTouched();
            return;
        }
        this.procesando.set(true);
        this.error.set(null);

        const v = this.form.getRawValue();
        this.service.update({
            id: this.id,
            apellido: v.apellido!,
            nombres: v.nombres!,
            cuenta: v.cuenta!,
            perfil_id: v.perfil_id!,
            correo: v.correo!,
            estado: v.estado!,
            // Campos que el form no edita pero el backend necesita intactos
            fechaAlta: this.original?.fechaAlta,
            resetPass: this.original?.resetPass,
            // Clave: si va vacía, el backend conserva la actual
            clave: v.clave ?? ''
        }).subscribe({
            next: () => this.router.navigate(['/user']),
            error: err => {
                this.error.set(err?.error?.error ?? 'No se pudo actualizar el usuario.');
                this.procesando.set(false);
            }
        });
    }

    eliminar(): void {
        if (!confirm('¿Eliminar este usuario?')) return;
        this.procesando.set(true);
        this.service.delete(this.id).subscribe({
            next: () => this.router.navigate(['/user']),
            error: err => {
                this.error.set(err?.error?.error ?? 'No se pudo eliminar el usuario.');
                this.procesando.set(false);
            }
        });
    }
}