import { Injectable } from '@angular/core';
import jsPDF from 'jspdf';
import autoTable from 'jspdf-autotable';
import { Sale } from '../models/sale.model';

// Días de validez de un presupuesto 
const DIAS_VALIDEZ_PRESUPUESTO = 15;

/**
 * Genera PDFs en el cliente con jsPDF + autotable.
 * - exportarListado(): tablas genéricas (categorías, productos, usuarios, ventas).
 * - exportarVenta(): el comprobante individual de una venta/presupuesto.
 */
@Injectable({ providedIn: 'root' })
export class PdfService {

    private money(n: number): string {
        return Number(n || 0).toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    private encabezado(doc: jsPDF, titulo: string): void {
        doc.setFontSize(16);
        doc.setTextColor(0);
        doc.text('CompuStack', 14, 18);
        doc.setFontSize(10);
        doc.setTextColor(120);
        doc.text('Sistema de Gestión', 14, 23);

        doc.setFontSize(13);
        doc.setTextColor(0);
        doc.text(titulo, 14, 33);

        doc.setFontSize(9);
        doc.setTextColor(120);
        doc.text('Emitido: ' + new Date().toLocaleString('es-AR'), 14, 38);
        doc.setTextColor(0);
    }

    /** Tabla genérica para los listados de cada módulo. */
    exportarListado(titulo: string, columnas: string[], filas: (string | number)[][], archivo: string): void {
        const doc = new jsPDF();
        this.encabezado(doc, titulo);
        autoTable(doc, {
            head: [columnas],
            body: filas,
            startY: 44,
            styles: { fontSize: 9, cellPadding: 2 },
            headStyles: { fillColor: [33, 37, 41] }
        });
        doc.save(`${archivo}.pdf`);
    }

    /** Comprobante individual: presupuesto o venta. */
    exportarVenta(v: Sale): void {
        const doc = new jsPDF();
        const esPresupuesto = v.estado === 'presupuesto';
        const titulo = (esPresupuesto ? 'Presupuesto' : 'Venta') + ' N° ' + v.numero;
        this.encabezado(doc, titulo);

        // Datos de cabecera
        let y = 46;
        doc.setFontSize(10);
        doc.text(`Cliente: ${v.cliente || '—'}`, 14, y);
        doc.text(`Vendedor: ${v.vendedor || ''}`, 14, y + 5);
        doc.text(`Fecha: ${new Date(v.fecha).toLocaleString('es-AR')}`, 14, y + 10);

        let inicioTabla = y + 16;

        // Validez (solo presupuestos)
        if (esPresupuesto) {
            const validoHasta = new Date(v.fecha);
            validoHasta.setDate(validoHasta.getDate() + DIAS_VALIDEZ_PRESUPUESTO);
            doc.setTextColor(180, 95, 0);
            doc.setFontSize(10);
            doc.text(
                `Presupuesto válido por ${DIAS_VALIDEZ_PRESUPUESTO} días — hasta el ${validoHasta.toLocaleDateString('es-AR')}`,
                14, y + 16
            );
            doc.setTextColor(0);
            inicioTabla = y + 22;
        }

        // Detalle de líneas
        autoTable(doc, {
            head: [['Producto', 'Cant.', 'Precio unit.', 'Subtotal']],
            body: (v.detalles || []).map(d => [
                d.producto ?? '',
                d.cantidad,
                '$ ' + this.money(d.precioUnit),
                '$ ' + this.money(d.subtotal)
            ]),
            startY: inicioTabla,
            styles: { fontSize: 9, cellPadding: 2 },
            headStyles: { fillColor: [33, 37, 41] },
            columnStyles: { 1: { halign: 'right' }, 2: { halign: 'right' }, 3: { halign: 'right' } }
        });

        // Totales (alineados a la derecha)
        let yTot = (doc as any).lastAutoTable.finalY + 8;
        const right = 196;
        doc.setFontSize(10);
        doc.text(`Subtotal: $ ${this.money(v.subtotal)}`, right, yTot, { align: 'right' });
        doc.text(`Descuento (${this.money(v.descuentoPorcentaje)}%): - $ ${this.money(v.descuento)}`, right, yTot + 5, { align: 'right' });
        doc.setFontSize(12);
        doc.text(`Total: $ ${this.money(v.total)}`, right, yTot + 12, { align: 'right' });

        // Pagos (si corresponde)
        if (!esPresupuesto && v.pagos && v.pagos.length > 0) {
            autoTable(doc, {
                head: [['Pago - Fecha', 'Método', 'Monto', 'Referencia']],
                body: v.pagos.map(p => [
                    new Date(p.fecha).toLocaleString('es-AR'),
                    p.metodo,
                    '$ ' + this.money(p.monto),
                    p.referencia ?? '—'
                ]),
                startY: yTot + 18,
                styles: { fontSize: 8, cellPadding: 2 },
                headStyles: { fillColor: [108, 117, 125] }
            });
            const yPag = (doc as any).lastAutoTable.finalY + 6;
            doc.setFontSize(10);
            doc.text(`Pagado: $ ${this.money(v.pagado ?? 0)}`, right, yPag, { align: 'right' });
            doc.text(`Saldo: $ ${this.money(v.saldo ?? 0)}`, right, yPag + 5, { align: 'right' });
        }

        doc.save(`${esPresupuesto ? 'presupuesto' : 'venta'}-${v.numero}.pdf`);
    }
}