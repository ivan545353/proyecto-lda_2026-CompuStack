import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { FormArray, FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { DecimalPipe } from '@angular/common';
import { SaleService } from '../../core/sale/sale.service';
import { ItemService } from '../../core/item/item.service';
import { Item } from '../../core/models/item.model';

@Component({
    selector: 'app-sale-edit',
    standalone: true,
    imports: [ReactiveFormsModule, RouterLink, DecimalPipe],
    templateUrl: './sale-edit.component.html'
})
export class SaleEditComponent implements OnInit {
    private fb = inject(FormBuilder);
    private service = inject(SaleService);
    private itemService = inject(ItemService);
    private router = inject(Router);
    private route = inject(ActivatedRoute);

    id = Number(this.route.snapshot.paramMap.get('id'));

    items = signal<Item[]>([]);
    busqueda = signal('');
    error = signal<string | null>(null);
    guardando = signal(false);
    editable = signal(true); // false si la venta ya no es presupuesto

    subtotal = signal(0);
    descuentoMonto = signal(0);
    total = signal(0);

    form = this.fb.group({
        cliente: [''],
        observaciones: [''],
        descuentoPorcentaje: [0, [Validators.min(0), Validators.max(100)]],
        detalles: this.fb.array([])
    });

    get detalles(): FormArray {
        return this.form.get('detalles') as FormArray;
    }

    resultados = computed(() => {
        const q = this.busqueda().toLowerCase().trim();
        let list = this.items();
        if (q) {
            list = list.filter(i =>
                i.nombre.toLowerCase().includes(q) || i.codigo.toLowerCase().includes(q));
        }
        return list.slice(0, 25);
    });

    ngOnInit(): void {
        // Primero el catálogo (necesario para los precios y el stock), luego la venta
        this.itemService.list().subscribe({
            next: items => { this.items.set(items); this.cargarVenta(); },
            error: () => this.error.set('No se pudieron cargar los productos.')
        });
        this.form.valueChanges.subscribe(() => this.recalcular());
    }

    private cargarVenta(): void {
        this.service.load(this.id).subscribe({
            next: v => {
                if (v.estado !== 'presupuesto') {
                    this.editable.set(false);
                    this.error.set(`Solo se pueden editar presupuestos. Esta venta está en estado "${v.estado}".`);
                    return;
                }
                this.form.patchValue({
                    cliente: v.cliente ?? '',
                    observaciones: v.observaciones ?? '',
                    descuentoPorcentaje: v.descuentoPorcentaje ?? 0
                });
                for (const d of v.detalles ?? []) {
                    const stock = this.stockDe(d.productoId);
                    this.detalles.push(this.fb.group({
                        productoId: [d.productoId],
                        cantidad: [d.cantidad, [Validators.required, Validators.min(1), Validators.max(stock)]]
                    }));
                }
                this.recalcular();
            },
            error: err => this.error.set(err?.error?.error ?? 'No se encontró el presupuesto.')
        });
    }

    itemDe(id: number): Item | undefined {
        return this.items().find(i => i.id === Number(id));
    }

    cantidadEnCarrito(id: number): number {
        const l = this.detalles.controls.find(c => Number(c.value.productoId) === id);
        return l ? Number(l.value.cantidad) : 0;
    }

    agregar(item: Item): void {
        if (item.stock <= 0) return;
        const existente = this.detalles.controls.find(c => Number(c.value.productoId) === item.id);
        if (existente) {
            const actual = Number(existente.value.cantidad);
            if (actual < item.stock) existente.patchValue({ cantidad: actual + 1 });
        } else {
            this.detalles.push(this.fb.group({
                productoId: [item.id],
                cantidad: [1, [Validators.required, Validators.min(1), Validators.max(item.stock)]]
            }));
        }
        this.recalcular();
    }

    quitarLinea(i: number): void {
        this.detalles.removeAt(i);
        this.recalcular();
    }

    precioDe(id: number): number {
        const it = this.itemDe(id);
        return it ? Number(it.precio) : 0;
    }

    stockDe(id: number): number {
        const it = this.itemDe(id);
        return it ? it.stock : 0;
    }

    subtotalLinea(i: number): number {
        const c = this.detalles.at(i).value;
        return this.precioDe(Number(c.productoId)) * Number(c.cantidad || 0);
    }

    private recalcular(): void {
        let sub = 0;
        for (let i = 0; i < this.detalles.length; i++) sub += this.subtotalLinea(i);
        const pct = Number(this.form.get('descuentoPorcentaje')?.value || 0);
        const descMonto = sub * pct / 100;
        this.subtotal.set(sub);
        this.descuentoMonto.set(descMonto);
        this.total.set(Math.max(sub - descMonto, 0));
    }

    guardar(): void {
        if (this.detalles.length === 0) {
            this.error.set('Agregá al menos un producto al carrito.');
            return;
        }
        if (this.form.invalid) {
            this.form.markAllAsTouched();
            this.error.set('Revisá las cantidades: no pueden superar el stock disponible.');
            return;
        }

        this.error.set(null);
        this.guardando.set(true);

        const v = this.form.getRawValue();
        this.service.update(this.id, {
            cliente: v.cliente ?? '',
            observaciones: v.observaciones ?? '',
            descuentoPorcentaje: Number(v.descuentoPorcentaje || 0),
            detalles: this.detalles.controls.map(c => ({
                productoId: Number(c.value.productoId),
                cantidad: Number(c.value.cantidad)
            }))
        }).subscribe({
            next: () => this.router.navigate(['/sale/detail', this.id]),
            error: err => {
                this.error.set(err?.error?.error ?? 'No se pudo guardar los cambios.');
                this.guardando.set(false);
            }
        });
    }
}