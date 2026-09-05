export interface User {
    id: number;
    apellido: string;
    nombres: string;
    cuenta: string;
    perfil_id: number;
    perfil?: string;
    correo: string;
    estado: number;       // 1 = activo, 0 = inactivo
    fechaAlta?: string;
    resetPass?: number;
}

export interface Profile {
    id: number;
    nombre: string;
}