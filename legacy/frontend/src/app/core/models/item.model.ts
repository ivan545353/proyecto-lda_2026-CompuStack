// 'categoria' (nombre) viene del JOIN y es solo lectura; lo que se
// envía/guarda es 'categoriaId' (el número de la FK).
export interface Item {
    id: number;
    nombre: string;
    codigo: string;
    descripcion: string;
    categoriaId: number;
    categoria?: string;
    precio: number;
    stock: number;
}

// Lo que mandamos al crear (sin id ni el nombre de categoría).
export type ItemPayload = Omit<Item, 'id' | 'categoria'>;