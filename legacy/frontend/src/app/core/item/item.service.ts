import { Injectable, inject } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable, map } from 'rxjs';
import { API_URL } from '../../core/api/api.constants';
import { ApiResponse } from '../../core/models/api-response.model';
import { Item, ItemPayload } from '../../core/models/item.model';

@Injectable({ providedIn: 'root' })
export class ItemService {
    private http = inject(HttpClient);
    private base = `${API_URL}item`;

    list(): Observable<Item[]> {
        return this.http
            .get<ApiResponse<Item[]>>(`${this.base}/list`)
            .pipe(map(res => res.result ?? []));
    }

    load(id: number): Observable<Item> {
        return this.http
            .get<ApiResponse<Item>>(`${this.base}/load/${id}`)
            .pipe(map(res => res.result));
    }

    save(item: ItemPayload): Observable<string> {
        return this.http
            .post<ApiResponse<unknown>>(`${this.base}/save`, item)
            .pipe(map(res => res.message));
    }

    update(item: Item): Observable<string> {
        return this.http
            .put<ApiResponse<unknown>>(`${this.base}/update`, item)
            .pipe(map(res => res.message));
    }

    delete(id: number): Observable<string> {
        return this.http
            .delete<ApiResponse<unknown>>(`${this.base}/delete/${id}`)
            .pipe(map(res => res.message));
    }
}