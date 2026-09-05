import { Component, OnInit, inject, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { ItemService } from '../../core/item/item.service';
import { CategoryService } from '../../core/category/category.service';
import { Category } from '../../core/models/category.model';

@Component({
    selector: 'app-item-create',
    standalone: true,
    imports: [ReactiveFormsModule, RouterLink],
    templateUrl: './item-create.component.html'
})
export class ItemCreateComponent implements OnInit {
    private fb = inject(FormBuilder);
    private service = inject(ItemService);
    private categoryService = inject(CategoryService);
    private router = inject(Router);

    categorias = signal<Category[]>([]);
    error = signal<string | null>(null);
    guardando = signal(false);

    form = this.fb.group({
        nombre: ['', [Validators.required, Validators.minLength(2), Validators.maxLength(100)]],
        codigo: ['', [Validators.required, Validators.maxLength(25)]],
        descripcion: ['', [Validators.minLength(10)]],
        categoriaId: [null as number | null, [Validators.required]],
        precio: [null as number | null, [Validators.required, Validators.min(0)]],
        stock: [null as number | null, [Validators.required, Validators.min(0)]]
    });

    ngOnInit(): void {
        // Reusa el CategoryService que ya existe para llenar el desplegable
        this.categoryService.list().subscribe({
            next: cats => this.categorias.set(cats),
            error: () => this.error.set('No se pudieron cargar las categorías.')
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
            nombre: v.nombre!,
            codigo: v.codigo!,
            descripcion: v.descripcion ?? '',
            categoriaId: v.categoriaId!,
            precio: v.precio!,
            stock: v.stock!
        }).subscribe({
            next: () => this.router.navigate(['/item']),
            error: err => {
                this.error.set(err?.error?.error ?? 'No se pudo guardar el producto.');
                this.guardando.set(false);
            }
        });
    }
}