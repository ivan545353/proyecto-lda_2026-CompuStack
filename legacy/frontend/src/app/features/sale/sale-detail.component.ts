import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { DatePipe, DecimalPipe } from '@angular/common';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { SaleService } from '../../core/sale/sale.service';
import { Sale, SaleEstado } from '../../core/models/sale.model';
import { AuthService } from '../../core/auth/auth.service';
import { PdfService } from '../../core/pdf/pdf.service';

@Component({
    selector: 'app-sale-detail',
    standalone: true,
    imports: [RouterLink, DatePipe, DecimalPipe, ReactiveFormsModule],
    templateUrl: './sale-detail.component.html'
})
export class SaleDetailComponent implements OnInit {
    private service = inject(SaleService);
    private auth = inject(AuthService);
    private router = inject(Router);
    private route = inject(ActivatedRoute);
    private fb = inject(FormBuilder);
    private pdf = inject(PdfService);

    private id = Number(this.route.snapshot.paramMap.get('id'));

    venta = signal<Sale | null>(null);
    error = signal<string | null>(null);
    procesando = signal(false);

    metodosPago = [
        { valor: 'efectivo', etiqueta: 'Efectivo' },
        { valor: 'transferencia', etiqueta: 'Transferencia' },
        { valor: 'mercadopago', etiqueta: 'MercadoPago' },
        { valor: 'qr', etiqueta: 'QR' }
    ];

    pagoForm = this.fb.group({
        metodo: ['efectivo', [Validators.required]],
        monto: [0, [Validators.required, Validators.min(0.01)]],
        referencia: ['']
    });

    private perfil = computed(() => this.auth.perfil() ?? '');
    esAdmin = computed(() => this.perfil() === 'Administrador');
    esVendedor = computed(() => this.perfil() === 'Vendedor');

    puedeConfirmar = computed(() =>
        this.venta()?.estado === 'presupuesto' && (this.esAdmin() || this.esVendedor()));
    // Cobrar lo pueden hacer Admin y Vendedor (modelo mostrador)
    puedeCobrar = computed(() => {
        const v = this.venta();
        return !!v && v.estado === 'confirmada' && (v.saldo ?? 0) > 0 &&
            (this.esAdmin() || this.esVendedor());
    });
    puedeAnular = computed(() => {
        const e = this.venta()?.estado;
        if (!e || e === 'anulada') return false;
        if (this.esAdmin()) return true;
        return this.esVendedor() && e === 'presupuesto';
    });
    puedeEliminar = computed(() =>
        this.venta()?.estado === 'presupuesto' && (this.esAdmin() || this.esVendedor()));

    badgeClass = computed(() => {
        switch (this.venta()?.estado) {
            case 'presupuesto': return 'text-bg-warning';
            case 'confirmada': return 'text-bg-info';
            case 'cobrada': return 'text-bg-success';
            case 'anulada': return 'text-bg-secondary';
            default: return 'text-bg-light';
        }
    });

    ngOnInit(): void {
        this.cargar();
    }

    cargar(): void {
        this.service.load(this.id).subscribe({
            next: v => {
                this.venta.set(v);
                // Sugiere el saldo pendiente como monto del próximo pago
                this.pagoForm.patchValue({ monto: v.saldo ?? 0 });
            },
            error: err => this.error.set(err?.error?.error ?? 'No se encontró la venta.')
        });
    }

    exportarPdf(): void {
        const v = this.venta();
        if (v) this.pdf.exportarVenta(v);
    }

    cambiarEstado(nuevo: SaleEstado, mensaje: string): void {
        if (!confirm(mensaje)) return;
        this.procesando.set(true);
        this.error.set(null);
        this.service.updateEstado(this.id, nuevo).subscribe({
            next: () => { this.procesando.set(false); this.cargar(); },
            error: err => {
                this.error.set(err?.error?.error ?? 'No se pudo cambiar el estado.');
                this.procesando.set(false);
            }
        });
    }

    registrarPago(): void {
        if (this.pagoForm.invalid) {
            this.pagoForm.markAllAsTouched();
            return;
        }
        this.procesando.set(true);
        this.error.set(null);
        const v = this.pagoForm.getRawValue();
        this.service.cobrar(this.id, {
            metodo: v.metodo!,
            monto: Number(v.monto),
            referencia: v.referencia ?? ''
        }).subscribe({
            next: () => {
                this.procesando.set(false);
                this.pagoForm.patchValue({ referencia: '' });
                this.cargar();
            },
            error: err => {
                this.error.set(err?.error?.error ?? 'No se pudo registrar el pago.');
                this.procesando.set(false);
            }
        });
    }

    eliminar(): void {
        if (!confirm('¿Eliminar este presupuesto?')) return;
        this.procesando.set(true);
        this.service.delete(this.id).subscribe({
            next: () => this.router.navigate(['/sale']),
            error: err => {
                this.error.set(err?.error?.error ?? 'No se pudo eliminar.');
                this.procesando.set(false);
            }
        });
    }
}