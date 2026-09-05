import { Injectable, inject } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable, map } from 'rxjs';
import { API_URL } from '../../core/api/api.constants';
import { ApiResponse } from '../../core/models/api-response.model';
import { User, Profile } from '../../core/models/user.model';

@Injectable({ providedIn: 'root' })
export class UserService {
    private http = inject(HttpClient);
    private base = `${API_URL}user`;

    list(): Observable<User[]> {
        return this.http
            .get<ApiResponse<User[]>>(`${this.base}/list`)
            .pipe(map(res => res.result ?? []));
    }

    load(id: number): Observable<User> {
        return this.http
            .get<ApiResponse<User>>(`${this.base}/load/${id}`)
            .pipe(map(res => res.result));
    }

    // Endpoint nuevo: alimenta el <select> de perfil
    listProfiles(): Observable<Profile[]> {
        return this.http
            .get<ApiResponse<Profile[]>>(`${this.base}/perfiles`)
            .pipe(map(res => res.result ?? []));
    }

    save(user: Partial<User> & { clave: string }): Observable<string> {
        return this.http
            .post<ApiResponse<unknown>>(`${this.base}/save`, user)
            .pipe(map(res => res.message));
    }

    update(user: Partial<User> & { clave?: string }): Observable<string> {
        return this.http
            .put<ApiResponse<unknown>>(`${this.base}/update`, user)
            .pipe(map(res => res.message));
    }

    // Datos del usuario logueado (lee el id del token en el backend)
    getCurrent(): Observable<User> {
        return this.http
            .get<ApiResponse<User>>(`${this.base}/getCurrent`)
            .pipe(map(res => res.result));
    }

    changePassword(payload: { currentPassword: string; newPassword: string; confirmPassword: string }): Observable<string> {
        return this.http
            .put<ApiResponse<unknown>>(`${this.base}/changePassword`, payload)
            .pipe(map(res => res.message));
    }

    delete(id: number): Observable<string> {
        return this.http
            .delete<ApiResponse<unknown>>(`${this.base}/delete/${id}`)
            .pipe(map(res => res.message));
    }
}