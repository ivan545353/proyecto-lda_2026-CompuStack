<?php

namespace App\Services;

use App\Exceptions\ReglaDeNegocioException;
use App\Exceptions\StockInsuficienteException;
use App\Exceptions\TransicionInvalidaException;
use App\Models\IntentoPago;
use App\Models\User;
use App\Models\Venta;
use App\Pagos\Consulta;
use App\Pagos\PasarelaNoDisponible;
use App\Pagos\Pasarelas;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Los intentos de pago de la tienda (tramo G): abrirlos, preguntar al proveedor y aplicar lo que
 * dice. La regla de forma: **ninguna llamada HTTP con la venta bloqueada**. Se consulta afuera;
 * adentro se bloquea, se relee y se aplica. Un aprobado se guarda en su propia transacción y se
 * acredita después: si la acreditación falla, la aprobación no se pierde (§1.3 E).
 *
 * El candado es la fila de la venta, como en `PagoService`: venta primero, intento después.
 *
 * Métodos:
 *   pedidoDe(), intentoDe()    lo de esta cuenta; ajeno, 404
 *   iniciar()                  abre un intento, o devuelve el pendiente (§6.6)
 *   verificar()                consulta al proveedor y aplica el resultado
 *   resolverSimulado()         aprueba o rechaza un intento del simulador
 *   verificarVencidos()        para el comando: los pendientes cuyo plazo pasó
 *   reintentarAcreditaciones() para el comando: los aprobados que quedaron sin acreditar
 */
class IntentoPagoService
{
    public function __construct(private Pasarelas $pasarelas, private PagoService $pagos) {}

    /**
     * Un pedido de la tienda de esta cuenta. Ajeno o inexistente, lo mismo: 404 (HL-70).
     *
     * @throws ModelNotFoundException
     */
    public function pedidoDe(User $usuario, int $pedido): Venta
    {
        $cliente = $usuario->cliente ?? throw (new ModelNotFoundException)->setModel(Venta::class, [$pedido]);

        return Venta::delCliente($cliente)->findOrFail($pedido);
    }

    /**
     * Un intento de un pedido de esta cuenta; con `$pedido`, además dentro de ese pedido.
     *
     * @throws ModelNotFoundException
     */
    public function intentoDe(User $usuario, int $intento, ?int $pedido = null): IntentoPago
    {
        $cliente = $usuario->cliente ?? throw (new ModelNotFoundException)->setModel(IntentoPago::class, [$intento]);

        return IntentoPago::query()
            ->whereHas('venta', fn ($q) => $q->delCliente($cliente))
            ->when($pedido !== null, fn ($q) => $q->where('venta_id', $pedido))
            ->findOrFail($intento);
    }

    /**
     * @return array{intento: IntentoPago, creado: bool}
     *
     * @throws ReglaDeNegocioException
     */
    public function iniciar(Venta $venta, string $pasarela): array
    {
        if (! $this->pasarelas->habilitada($pasarela)) {
            throw new ReglaDeNegocioException('Ese medio de pago no está disponible ahora. Elegí otro.');
        }

        // Un pendiente cuyo plazo pasó se le pregunta al proveedor antes: pudo haberse pagado.
        $venta->intentosPago()->where('estado', 'pendiente')->where('vence_at', '<', now())
            ->get()->each(fn (IntentoPago $i) => $this->verificar($i));

        ['intento' => $intento, 'creado' => $creado] = DB::transaction(function () use ($venta, $pasarela) {
            $v = Venta::lockForUpdate()->findOrFail($venta->id);

            $this->exigirQueAdmitaIntentos($v);

            // Como mucho un intento pendiente por pedido, visto con la venta bloqueada (§6.6). Uno
            // vencido que sigue pendiente es porque el proveedor no contestó: se devuelve ése.
            $pendiente = $v->intentosPago()->where('estado', 'pendiente')->lockForUpdate()->latest('id')->first();

            if ($pendiente !== null) {
                return ['intento' => $pendiente, 'creado' => false];
            }

            $intento = new IntentoPago;
            $intento->venta_id   = $v->id;
            $intento->pasarela   = $pasarela;
            $intento->estado     = 'pendiente';
            $intento->importe    = number_format($v->saldo(), 2, '.', '');
            $intento->referencia = (string) Str::uuid();
            $intento->vence_at   = now()->addMinutes((int) config('tienda.pagos.intento_minutos'));
            $intento->save();

            // La reserva no vence con un intento pendiente: si quedaba antes, se estira (D-9).
            if ($v->reserva_vence->lessThan($intento->vence_at)) {
                $v->reserva_vence = $intento->vence_at;
                $v->save();
            }

            return ['intento' => $intento, 'creado' => true];
        });

        if ($creado) {
            $intento = $this->abrirEnLaPasarela($intento);
        }

        return ['intento' => $intento->fresh(), 'creado' => $creado];
    }

    /** Si está pendiente, pregunta al proveedor (afuera) y aplica (adentro); un aprobado, se acredita. */
    public function verificar(IntentoPago $intento): IntentoPago
    {
        if ($intento->estado === 'pendiente') {
            $consulta = $this->pasarelas->para($intento->pasarela)->consultar($intento);
            $intento  = $this->aplicar($intento, $consulta);
        }

        return $this->acreditarSiCorresponde($intento);
    }

    /**
     * El resultado que elige quien prueba. Sólo el simulador y sólo un intento pendiente.
     *
     * @throws ReglaDeNegocioException
     */
    public function resolverSimulado(IntentoPago $intento, string $resultado): IntentoPago
    {
        // Las rutas sólo existen con la llave encendida; esto ataja que se apague con la app corriendo.
        if (! $this->pasarelas->habilitada('simulador')) {
            throw new ReglaDeNegocioException('El simulador de pagos no está disponible.');
        }

        if ($intento->pasarela !== 'simulador') {
            throw new ReglaDeNegocioException('Este pago no es del simulador: su resultado lo dice el proveedor.');
        }

        if (! in_array($resultado, ['aprobado', 'rechazado'], true)) {
            throw new ReglaDeNegocioException('El simulador sólo aprueba o rechaza.');
        }

        $intento = $this->aplicar($intento, new Consulta($resultado, importe: (string) $intento->importe), estricto: true);

        return $this->acreditarSiCorresponde($intento);
    }

    /** Los pendientes cuyo plazo pasó: se consultan una última vez. Devuelve cuántos dejaron de estar pendientes. */
    public function verificarVencidos(): int
    {
        return IntentoPago::query()
            ->where('estado', 'pendiente')
            ->where('vence_at', '<', now())
            ->orderBy('id')
            ->get()
            ->filter(fn (IntentoPago $i) => $this->verificar($i)->estado !== 'pendiente')
            ->count();
    }

    /** Los aprobados que quedaron sin acreditar: se reintentan. Devuelve cuántos se acreditaron. */
    public function reintentarAcreditaciones(): int
    {
        return IntentoPago::query()
            ->where('estado', 'aprobado')
            ->whereNull('acreditado_at')
            ->where(fn ($q) => $q->whereNull('detalle')->orWhere('detalle', '!=', IntentoPago::IMPORTE_DISTINTO))
            ->orderBy('id')
            ->get()
            ->filter(fn (IntentoPago $i) => $this->acreditarSiCorresponde($i)->acreditado_at !== null)
            ->count();
    }

    // ------------------------------------------------------------------
    // Interno
    // ------------------------------------------------------------------

    /** @throws ReglaDeNegocioException */
    private function exigirQueAdmitaIntentos(Venta $v): void
    {
        $motivo = match (true) {
            $v->canal !== 'online'          => 'no es un pedido de la tienda',
            $v->medio_pago !== 'pasarela'   => 'se paga en el local, no por una pasarela',
            $v->estado !== 'pendiente_pago' => "está «{$v->estadoTexto()}» y ya no espera un pago",
            $v->reserva_vence === null || ! $v->reserva_vence->isFuture() => 'ya no tiene la mercadería reservada',
            default => null,
        };

        if ($motivo !== null) {
            throw new ReglaDeNegocioException("El pedido {$v->numeroFormateado()} {$motivo}, así que no se puede pagar desde acá.");
        }
    }

    /** La llamada a la pasarela, fuera de la transacción. Si no abre, el intento queda en `error`. */
    private function abrirEnLaPasarela(IntentoPago $intento): IntentoPago
    {
        try {
            $inicio = $this->pasarelas->para($intento->pasarela)->iniciar($intento);
        } catch (PasarelaNoDisponible $e) {
            Log::warning('No se pudo abrir el intento de pago.', ['intento' => $intento->id, 'motivo' => $e->getMessage()]);

            return DB::transaction(function () use ($intento) {
                Venta::lockForUpdate()->findOrFail($intento->venta_id);
                $i = IntentoPago::lockForUpdate()->findOrFail($intento->id);

                if ($i->estado === 'pendiente') {
                    $this->resolver($i, 'error');
                }

                return $i;
            });
        }

        $intento->url_pago = $inicio->url;
        $intento->save();

        return $intento;
    }

    /**
     * Aplica lo que dijo el proveedor: bloquea la venta, relee el intento y sólo lo cambia si sigue
     * pendiente. Con `estricto`, un intento que ya no está pendiente es un error (el simulador).
     *
     * @throws ReglaDeNegocioException
     */
    private function aplicar(IntentoPago $intento, Consulta $consulta, bool $estricto = false): IntentoPago
    {
        return DB::transaction(function () use ($intento, $consulta, $estricto) {
            Venta::lockForUpdate()->findOrFail($intento->venta_id);
            $i = IntentoPago::lockForUpdate()->findOrFail($intento->id);

            if ($i->estado !== 'pendiente') {
                if ($estricto) {
                    throw new ReglaDeNegocioException('Este pago ya tiene resultado: para volver a intentar, iniciá otro.');
                }

                return $i;   // otro pedido lo resolvió mientras se consultaba
            }

            $i->payment_id = $consulta->paymentId ?? $i->payment_id;
            $i->detalle    = $consulta->detalle ?? $i->detalle;

            match ($consulta->estado) {
                'aprobado'  => $this->aprobar($i, $consulta),
                'rechazado' => $this->resolver($i, 'rechazado'),
                // Vence sólo si el proveedor contestó: sin respuesta, la plata pudo haber entrado.
                'pendiente' => $consulta->respondida && $i->vence_at->isPast()
                    ? $this->resolver($i, 'vencido')
                    : $i->save(),
            };

            return $i;
        });
    }

    /** Aprobado, y si el proveedor cobró otro importe, marcado para revisión (HL-72). */
    private function aprobar(IntentoPago $i, Consulta $consulta): void
    {
        if ($consulta->importe !== null && $this->centavos($consulta->importe) !== $this->centavos($i->importe)) {
            Log::warning('El proveedor aprobó un importe distinto del intento.', [
                'intento' => $i->id, 'pedido' => $i->importe, 'cobrado' => $consulta->importe,
            ]);
            $i->detalle = IntentoPago::IMPORTE_DISTINTO;
        }

        $this->resolver($i, 'aprobado');
    }

    private function resolver(IntentoPago $i, string $estado): void
    {
        $i->estado      = $estado;
        $i->resuelto_at = now();
        $i->save();
    }

    /** Acredita en su propia transacción. Si falla, el intento sigue aprobado y lo reintenta el comando. */
    private function acreditarSiCorresponde(IntentoPago $intento): IntentoPago
    {
        if (! $intento->acreditable()) {
            return $intento;
        }

        try {
            $this->pagos->acreditarIntento($intento);
        } catch (ReglaDeNegocioException|TransicionInvalidaException|StockInsuficienteException $e) {
            Log::warning('Un pago aprobado quedó sin acreditar.', ['intento' => $intento->id, 'motivo' => $e->getMessage()]);
        }

        return $intento->fresh();
    }

    private function centavos(float|string $importe): int
    {
        return (int) round((float) $importe * 100);
    }
}
