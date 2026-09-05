import { Routes } from '@angular/router';
import { authGuard } from './core/auth/auth.guard';
import { LoginComponent } from './features/auth/login.component';
import { LayoutComponent } from './features/layout/layout.component';
import { HomeComponent } from './features/home/home.component';
import { CategoryIndexComponent } from './features/category/category-index.component';
import { CategoryCreateComponent } from './features/category/category-create.component';
import { CategoryEditComponent } from './features/category/category-edit.component';
import { ItemIndexComponent } from './features/item/item-index.component';
import { ItemCreateComponent } from './features/item/item-create.component';
import { ItemEditComponent } from './features/item/item-edit.component';
import { UserIndexComponent } from './features/user/user-index.component';
import { UserCreateComponent } from './features/user/user-create.component';
import { UserEditComponent } from './features/user/user-edit.component';
import { SaleIndexComponent } from './features/sale/sale-index.component';
import { SaleCreateComponent } from './features/sale/sale-create.component';
import { SaleDetailComponent } from './features/sale/sale-detail.component';
import { SaleEditComponent } from './features/sale/sale-edit.component';
import { AccountComponent } from './features/account/account.component';

export const routes: Routes = [
    { path: 'login', component: LoginComponent },

    {
        path: '',
        component: LayoutComponent,
        canActivate: [authGuard],
        children: [
            { path: 'home', component: HomeComponent },

            { path: 'category', component: CategoryIndexComponent },
            { path: 'category/create', component: CategoryCreateComponent },
            { path: 'category/edit/:id', component: CategoryEditComponent },

            { path: 'item', component: ItemIndexComponent },
            { path: 'item/create', component: ItemCreateComponent },
            { path: 'item/edit/:id', component: ItemEditComponent },

            { path: 'user', component: UserIndexComponent },
            { path: 'user/create', component: UserCreateComponent },
            { path: 'user/edit/:id', component: UserEditComponent },

            { path: 'sale', component: SaleIndexComponent },
            { path: 'sale/create', component: SaleCreateComponent },
            { path: 'sale/edit/:id', component: SaleEditComponent },
            { path: 'sale/detail/:id', component: SaleDetailComponent },

            { path: 'mi-cuenta', component: AccountComponent },

            { path: '', redirectTo: 'home', pathMatch: 'full' }
        ]
    },

    { path: '**', redirectTo: 'home' }
];