<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ventas', function (Blueprint $table) {
            $table->id();

            // Mostrador y online son el mismo hecho económico con distinto
            // origen. 'online' no se usa en la Etapa 1, pero el valor ya existe.
            $table->enum('canal', ['mostrador', 'online'])->default('mostrador');

            $table->foreignId('cliente_id')->nullable()->constrained('clientes');  // null = consumidor final
            $table->foreignId('usuario_id')->nullable()->constrained('users');     // vendedor; null en online

            // Los diez valores desde el inicio aunque la Etapa 1 use cuatro:
            // modificar un ENUM reescribe la tabla entera.
            $table->enum('estado', [
                'presupuesto', 'pendiente_pago', 'pagada', 'en_preparacion',
                'despachada', 'lista_retiro', 'entregada', 'cancelada',
                'devuelta_parcial', 'devuelta',
            ])->default('presupuesto');

            $table->enum('modo_entrega', ['retiro', 'envio'])->default('retiro');

            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('descuento', 12, 2)->default(0);
            $table->decimal('costo_envio', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->string('observaciones', 255)->nullable();
            $table->timestamps();

            // vale_id NO se agrega acá: la tabla vales es de la Etapa 2 y una
            // clave foránea no puede apuntar a algo que todavía no existe.
            // Entra después como ADD COLUMN nullable.

            $table->index(['estado', 'created_at']);
            $table->index('usuario_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ventas');
    }
};
