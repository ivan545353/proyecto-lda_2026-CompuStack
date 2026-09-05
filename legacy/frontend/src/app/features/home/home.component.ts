import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { forkJoin } from 'rxjs';
import { AuthService } from '../../core/auth/auth.service';
import { CategoryService } from '../../core/category/category.service';
import { ItemService } from '../../core/item/item.service';
import { UserService } from '../../core/user/user.service';
import { SaleService } from '../../core/sale/sale.service';

@Component({
  selector: 'app-home',
  standalone: true,
  imports: [RouterLink],
  templateUrl: './home.component.html'
})
export class HomeComponent implements OnInit {
  auth = inject(AuthService);
  private categoryService = inject(CategoryService);
  private itemService = inject(ItemService);
  private userService = inject(UserService);
  private saleService = inject(SaleService);

  cargando = signal(true);
  totalUsuarios = signal(0);
  totalProductos = signal(0);
  totalCategorias = signal(0);
  ventasHoy = signal(0);
  sinStock = signal(0);

  esAdmin = computed(() => this.auth.esAdmin());

  ngOnInit(): void {
    // El conteo de usuarios solo lo pedimos si es Admin (los demás no tienen permiso)
    const peticiones: any = {
      categorias: this.categoryService.list(),
      productos: this.itemService.list(),
      ventas: this.saleService.list()
    };
    if (this.esAdmin()) {
      peticiones.usuarios = this.userService.list();
    }

    forkJoin(peticiones).subscribe({
      next: (res: any) => {
        this.totalCategorias.set(res.categorias.length);
        this.totalProductos.set(res.productos.length);
        this.sinStock.set(res.productos.filter((p: any) => p.stock === 0).length);
        if (res.usuarios) this.totalUsuarios.set(res.usuarios.length);

        const hoy = new Date().toISOString().slice(0, 10);
        this.ventasHoy.set(
          res.ventas.filter((v: any) =>
            (v.fecha ?? '').slice(0, 10) === hoy && v.estado !== 'anulada').length
        );
        this.cargando.set(false);
      },
      error: () => this.cargando.set(false)
    });
  }
}