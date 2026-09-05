import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { DatePipe, DecimalPipe } from '@angular/common';
import { SaleService } from '../../core/sale/sale.service';
import { Sale } from '../../core/models/sale.model';
import { AuthService } from '../../core/auth/auth.service';
import { PdfService } from '../../core/pdf/pdf.service';

@Component({
    selector: 'app-sale-index',
    standalone: true,
    imports: [RouterLink, DatePipe, DecimalPipe],
    templateUrl: './sale-index.component.html'
})
export class SaleIndexComponent implements OnInit {
    private service = inject(SaleService);
    private auth = inject(AuthService);
    private pdf = inject(PdfService);

    ventas = signal<Sale[]>([]);
    cargando = signal(false);
    error = signal<string | null>(null);
    fEstado = signal('');

    // Solo Admin y Vendedor pueden iniciar ventas (la UI lo refleja; el backend lo exige)
    puedeCrear = computed(() => ['Administrador', 'Vendedor'].includes(this.auth.perfil() ?? ''));

    ventasFiltradas = computed(() => {
        const e = this.fEstado();
        return e ? this.ventas().filter(v => v.estado === e) : this.ventas();
    });

    ngOnInit(): void {
        this.cargar();
    }

    cargar(): void {
        this.cargando.set(true);
        this.error.set(null);
        this.service.list().subscribe({
            next: vs => { this.ventas.set(vs); this.cargando.set(false); },
            error: err => {
                this.error.set(err?.error?.error ?? 'No se pudieron cargar las ventas.');
                this.cargando.set(false);
            }
        });
    }

    badge(estado: string): string {
        switch (estado) {
            case 'presupuesto': return 'text-bg-warning';
            case 'confirmada': return 'text-bg-info';
            case 'cobrada': return 'text-bg-success';
            case 'anulada': return 'text-bg-secondary';
            default: return 'text-bg-light';
        }
    }

    exportarPdf(): void {
        const filas = this.ventasFiltradas().map(v => [
            v.numero,
            new Date(v.fecha).toLocaleDateString('es-AR'),
            v.cliente || '—',
            v.vendedor ?? '',
            v.estado,
            '$ ' + Number(v.total).toFixed(2)
        ]);
        this.pdf.exportarListado('Listado de ventas',
            ['N°', 'Fecha', 'Cliente', 'Vendedor', 'Estado', 'Total'], filas, 'ventas');
    }
}