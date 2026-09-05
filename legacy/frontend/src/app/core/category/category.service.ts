import { Injectable, inject } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable, map } from 'rxjs';
import { API_URL } from '../api/api.constants';
import { ApiResponse } from '../models/api-response.model';
import { Category } from '../models/category.model';

@Injectable({ providedIn: 'root' })
export class CategoryService {
    private http = inject(HttpClient);
    private base = `${API_URL}category`;

    list(): Observable<Category[]> {
        return this.http
            .get<ApiResponse<Category[]>>(`${this.base}/list`)
            .pipe(map(res => res.result ?? []));
    }

    load(id: number): Observable<Category> {
        return this.http
            .get<ApiResponse<Category>>(`${this.base}/load/${id}`)
            .pipe(map(res => res.result));
    }

    save(nombre: string): Observable<string> {
        return this.http
            .post<ApiResponse<unknown>>(`${this.base}/save`, { nombre })
            .pipe(map(res => res.message));
    }

    update(category: Category): Observable<string> {
        return this.http
            .put<ApiResponse<unknown>>(`${this.base}/update`, category)
            .pipe(map(res => res.message));
    }

    delete(id: number): Observable<string> {
        return this.http
            .delete<ApiResponse<unknown>>(`${this.base}/delete/${id}`)
            .pipe(map(res => res.message));
    }
}