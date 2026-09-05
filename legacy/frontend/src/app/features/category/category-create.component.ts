import { Component, inject, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { CategoryService } from '../../core/category/category.service';

@Component({
    selector: 'app-category-create',
    standalone: true,
    imports: [ReactiveFormsModule, RouterLink],
    templateUrl: './category-create.component.html'
})
export class CategoryCreateComponent {
    private fb = inject(FormBuilder);
    private service = inject(CategoryService);
    private router = inject(Router);

    error = signal<string | null>(null);
    guardando = signal(false);

    form = this.fb.group({
        nombre: ['', [
            Validators.required,
            Validators.minLength(2),
            Validators.maxLength(50),
            Validators.pattern(/^[A-Za-zÁÉÍÓÚáéíóúÑñ\s\-']+$/)
        ]]
    });

    guardar(): void {
        if (this.form.invalid) {
            this.form.markAllAsTouched();
            return;
        }
        this.error.set(null);
        this.guardando.set(true);

        this.service.save(this.form.getRawValue().nombre!).subscribe({
            next: () => this.router.navigate(['/category']),
            error: err => {
                this.error.set(err?.error?.error ?? 'No se pudo guardar la categoría.');
                this.guardando.set(false);
            }
        });
    }
}