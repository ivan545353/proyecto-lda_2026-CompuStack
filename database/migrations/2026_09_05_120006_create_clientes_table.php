<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clientes', function (Blueprint $table) {
            $table->id();

            // Null = cliente de mostrador sin cuenta. No se le exige contraseña
            // a alguien que compra una vez.
            $table->foreignId('user_id')->nullable()->unique()->constrained('users')->nullOnDelete();

            $table->string('razon_social', 150);
            $table->enum('tipo_doc', ['dni', 'cuit', 'cuil']);
            $table->string('nro_doc', 15)->nullable();

            // Decide Factura A o B. Es un dato del sujeto de la operación.
            $table->enum('condicion_iva', [
                'responsable_inscripto', 'monotributo', 'consumidor_final', 'exento',
            ])->default('consumidor_final');

            $table->string('email', 150)->nullable();
            $table->string('telefono', 30)->nullable();
            $table->timestamps();

            $table->unique(['tipo_doc', 'nro_doc']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clientes');
    }
};
