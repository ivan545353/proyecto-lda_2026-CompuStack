export interface SaleDetail {
    productoId: number;
    cantidad: number;
    precioUnit: number;
    subtotal: number;
    producto?: string;
}

export type SaleEstado = 'presupuesto' | 'confirmada' | 'cobrada' | 'anulada';

export interface Pago {
    id: number;
    metodo: string;
    monto: number;
    referencia: string | null;
    fecha: string;
}

export interface Sale {
    id: number;
    numero: number;
    usuario_id: number;
    cliente: string | null;
    fecha: string;
    estado: SaleEstado;
    subtotal: number;
    descuento: number;
    descuentoPorcentaje: number;
    total: number;
    observaciones: string | null;
    vendedor?: string;
    detalles?: SaleDetail[];
    pagos?: Pago[];
    pagado?: number;
    saldo?: number;
}

export interface SaleLinePayload {
    productoId: number;
    cantidad: number;
}

export interface SalePayload {
    cliente: string;
    observaciones: string;
    descuentoPorcentaje: number;
    detalles: SaleLinePayload[];
    confirmar?: boolean;
}

export interface PagoPayload {
    metodo: string;
    monto: number;
    referencia?: string;
}