import { Component, OnInit, inject, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { ItemService } from '../../core/item/item.service';
import { CategoryService } from '../../core/category/category.service';
import { Category } from '../../core/models/category.model';

@Component({
    selector: 'app-item-edit',
    standalone: true,
    imports: [ReactiveFormsModule, RouterLink],
    templateUrl: './item-edit.component.html'
})
export class ItemEditComponent implements OnInit {
    private fb = inject(FormBuilder);
    private service = inject(ItemService);
    private categoryService = inject(CategoryService);
    private router = inject(Router);
    private route = inject(ActivatedRoute);

    private id = Number(this.route.snapshot.paramMap.get('id'));

    categorias = signal<Category[]>([]);
    editando = signal(false);
    procesando = signal(false);
    error = signal<string | null>(null);

    form = this.fb.group({
        nombre: ['', [Validators.required, Validators.minLength(2), Validators.maxLength(100)]],
        codigo: ['', [Validators.required, Validators.maxLength(25)]],
        descripcion: ['', [Validators.minLength(10)]],
        categoriaId: [null as number | null, [Validators.required]],
        precio: [null as number | null, [Validators.required, Validators.min(0)]],
        stock: [null as number | null, [Validators.required, Validators.min(0)]]
    });

    ngOnInit(): void {
        this.categoryService.list().subscribe({
            next: cats => this.categorias.set(cats),
            error: () => this.error.set('No se pudieron cargar las categorías.')
        });

        this.service.load(this.id).subscribe({
            next: item => {
                this.form.patchValue(item);
                this.form.disable(); // arranca en modo "ver"
            },
            error: err => this.error.set(err?.error?.error ?? 'No se encontró el producto.')
        });
    }

    habilitarEdicion(): void {
        this.editando.set(true);
        this.form.enable();
    }

    cancelar(): void {
        this.editando.set(false);
        this.error.set(null);
        // Recarga los datos originales
        this.service.load(this.id).subscribe(item => {
            this.form.patchValue(item);
            this.form.disable();
        });
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
            nombre: v.nombre!,
            codigo: v.codigo!,
            descripcion: v.descripcion ?? '',
            categoriaId: v.categoriaId!,
            precio: v.precio!,
            stock: v.stock!
        }).subscribe({
            next: () => this.router.navigate(['/item']),
            error: err => {
                this.error.set(err?.error?.error ?? 'No se pudo actualizar el producto.');
                this.procesando.set(false);
            }
        });
    }

    eliminar(): void {
        if (!confirm('¿Eliminar este producto?')) return;
        this.procesando.set(true);
        this.service.delete(this.id).subscribe({
            next: () => this.router.navigate(['/item']),
            error: err => {
                this.error.set(err?.error?.error ?? 'No se pudo eliminar el producto.');
                this.procesando.set(false);
            }
        });
    }
}