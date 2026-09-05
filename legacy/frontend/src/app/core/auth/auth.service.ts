import { Injectable, signal, computed, inject } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable, map, tap } from 'rxjs';
import { API_URL } from '../api/api.constants';
import { ApiResponse } from '../models/api-response.model';

interface JwtPayload {
  usuarioID: number;
  cuenta: string;
  perfil: string;
  correo: string;
  exp: number;
}

@Injectable({ providedIn: 'root' })
export class AuthService {
  private http = inject(HttpClient);
  private readonly TOKEN_KEY = 'token';

  // Estado del usuario actual como signal: el navbar y los guards lo leen.
  private _payload = signal<JwtPayload | null>(this.decode(this.getToken()));

  // Señales derivadas (computed) para usar en las vistas sin lógica repetida.
  readonly usuario = computed(() => this._payload());
  readonly perfil = computed(() => this._payload()?.perfil ?? null);
  readonly esAdmin = computed(() => this._payload()?.perfil === 'Administrador');
  readonly estaLogueado = computed(() => {
    const p = this._payload();
    return !!p && p.exp * 1000 > Date.now(); // token presente y no vencido
  });

  /**
   * Pega a POST authentication/login. Las claves del body (userName/password)
   * son las que espera tu LoginDto.
   */
  login(cuenta: string, clave: string): Observable<string> {
    const body = { cuenta, clave };
    return this.http
      .post<ApiResponse<{ token: string }>>(`${API_URL}authentication/login`, body)
      .pipe(
        map(res => {
          if (res.error) throw new Error(res.error);
          return res.result.token;
        }),
        tap(token => this.setToken(token))
      );
  }

  logout(): void {
    localStorage.removeItem(this.TOKEN_KEY);
    this._payload.set(null);
  }

  getToken(): string | null {
    return localStorage.getItem(this.TOKEN_KEY);
  }

  private setToken(token: string): void {
    localStorage.setItem(this.TOKEN_KEY, token);
    this._payload.set(this.decode(token));
  }

  // Decodifica el payload del JWT (la parte del medio) para leer perfil, etc.
  // No valida la firma: eso es trabajo del backend. Acá solo es para la UI.
  private decode(token: string | null): JwtPayload | null {
    if (!token) return null;
    try {
      const payload = token.split('.')[1];
      return JSON.parse(atob(payload));
    } catch {
      return null;
    }
  }
}