import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { DecimalPipe } from '@angular/common';
import { ItemService } from '../../core/item/item.service';
import { Item } from '../../core/models/item.model';
import { PdfService } from '../../core/pdf/pdf.service';

@Component({
    selector: 'app-item-index',
    standalone: true,
    imports: [RouterLink, DecimalPipe],
    templateUrl: './item-index.component.html'
})
export class ItemIndexComponent implements OnInit {
    private service = inject(ItemService);
    private pdf = inject(PdfService);

    items = signal<Item[]>([]);
    cargando = signal(false);
    error = signal<string | null>(null);

    // Filtros (uno por signal). El filtrado es en vivo, sin botón "Aplicar".
    fNombre = signal('');
    fCodigo = signal('');
    fCategoria = signal('');
    fStock = signal('');

    itemsFiltrados = computed(() => {
        const n = this.fNombre().toLowerCase().trim();
        const c = this.fCodigo().toLowerCase().trim();
        const cat = this.fCategoria().toLowerCase().trim();
        const st = this.fStock();

        return this.items().filter(i => {
            const okNombre = !n || i.nombre.toLowerCase().includes(n);
            const okCodigo = !c || i.codigo.toLowerCase().includes(c);
            const okCat = !cat || (i.categoria ?? '').toLowerCase().includes(cat);
            const okStock =
                !st ||
                (st === 'disponible' && i.stock > 0) ||
                (st === 'agotado' && i.stock === 0);
            return okNombre && okCodigo && okCat && okStock;
        });
    });

    ngOnInit(): void {
        this.cargar();
    }

    cargar(): void {
        this.cargando.set(true);
        this.error.set(null);
        this.service.list().subscribe({
            next: items => { this.items.set(items); this.cargando.set(false); },
            error: err => {
                this.error.set(err?.error?.error ?? 'No se pudieron cargar los productos.');
                this.cargando.set(false);
            }
        });
    }

    limpiarFiltros(): void {
        this.fNombre.set('');
        this.fCodigo.set('');
        this.fCategoria.set('');
        this.fStock.set('');
    }

    exportarPdf(): void {
        const filas = this.itemsFiltrados().map(i => [
            i.nombre, i.codigo, i.categoria ?? '',
            '$ ' + Number(i.precio).toFixed(2), i.stock
        ]);
        this.pdf.exportarListado('Listado de productos',
            ['Nombre', 'Código', 'Categoría', 'Precio', 'Stock'], filas, 'productos');
    }

    eliminar(item: Item): void {
        if (!confirm(`¿Eliminar el producto "${item.nombre}"?`)) return;
        this.service.delete(item.id).subscribe({
            next: () => this.items.update(list => list.filter(i => i.id !== item.id)),
            error: err => alert(err?.error?.error ?? 'No se pudo eliminar el producto.')
        });
    }
}