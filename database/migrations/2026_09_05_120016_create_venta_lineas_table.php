<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('venta_lineas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('venta_id')->constrained('ventas')->cascadeOnDelete();
            $table->foreignId('producto_id')->constrained('productos');

            $table->string('descripcion', 150);
            $table->unsignedInteger('cantidad');
            $table->unsignedInteger('cantidad_devuelta')->default(0);   // habilita la devolución parcial

            // Congelados al momento de la venta: una venta de hace seis meses
            // tiene que seguir siendo reproducible después de un aumento.
            $table->decimal('precio_unitario', 12, 2);
            $table->decimal('alicuota_iva', 4, 2);
            $table->decimal('costo_unitario', 12, 2);   // sin él, el margen se calcula contra el costo de hoy

            // Se persisten porque son los importes que se declaran a AFIP.
            $table->decimal('neto', 12, 2);
            $table->decimal('iva', 12, 2);
            $table->decimal('total', 12, 2);

            $table->timestamps();

            $table->index('producto_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('venta_lineas');
    }
};
