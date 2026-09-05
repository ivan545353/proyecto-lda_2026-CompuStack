import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { UserService } from '../../core/user/user.service';
import { User } from '../../core/models/user.model';
import { PdfService } from '../../core/pdf/pdf.service';

@Component({
    selector: 'app-user-index',
    standalone: true,
    imports: [RouterLink],
    templateUrl: './user-index.component.html'
})
export class UserIndexComponent implements OnInit {
    private service = inject(UserService);
    private pdf = inject(PdfService);

    usuarios = signal<User[]>([]);
    cargando = signal(false);
    error = signal<string | null>(null);

    fCuenta = signal('');
    fCorreo = signal('');
    fPerfil = signal('');

    usuariosFiltrados = computed(() => {
        const cu = this.fCuenta().toLowerCase().trim();
        const co = this.fCorreo().toLowerCase().trim();
        const pe = this.fPerfil().toLowerCase().trim();
        return this.usuarios().filter(u =>
            (!cu || u.cuenta.toLowerCase().includes(cu)) &&
            (!co || u.correo.toLowerCase().includes(co)) &&
            (!pe || (u.perfil ?? '').toLowerCase().includes(pe))
        );
    });

    ngOnInit(): void {
        this.cargar();
    }

    cargar(): void {
        this.cargando.set(true);
        this.error.set(null);
        this.service.list().subscribe({
            next: us => { this.usuarios.set(us); this.cargando.set(false); },
            error: err => {
                this.error.set(err?.error?.error ?? 'No se pudieron cargar los usuarios.');
                this.cargando.set(false);
            }
        });
    }

    exportarPdf(): void {
        const filas = this.usuariosFiltrados().map(u => [
            `${u.apellido}, ${u.nombres}`, u.cuenta, u.perfil ?? '',
            u.correo, u.estado === 1 ? 'Activo' : 'Inactivo'
        ]);
        this.pdf.exportarListado('Listado de usuarios',
            ['Usuario', 'Cuenta', 'Perfil', 'Correo', 'Estado'], filas, 'usuarios');
    }

    eliminar(u: User): void {
        if (!confirm(`¿Eliminar la cuenta "${u.cuenta}"?`)) return;
        this.service.delete(u.id).subscribe({
            next: () => this.usuarios.update(list => list.filter(x => x.id !== u.id)),
            error: err => alert(err?.error?.error ?? 'No se pudo eliminar el usuario.')
        });
    }
}