<?php

namespace App\Pagos;

use App\Models\IntentoPago;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Checkout Pro de Mercado Pago con el cliente `Http`, sin el SDK (sería una librería nueva).
 *
 * Inicia creando una preferencia que vence con el intento (HL-71) y consulta buscando los pagos
 * por `external_reference`. Una referencia puede tener varios pagos, porque el comprador reintenta
 * dentro del checkout: manda el aprobado; si no, el que sigue en curso; rechazado sólo si todos lo
 * fueron (HL-72). Lo que no sabe traducir, o no le llega, lo deja pendiente (regla 16).
 */
final class PasarelaMercadoPago implements Pasarela
{
    private const APROBADO = ['approved'];

    private const EN_CURSO = ['pending', 'in_process', 'authorized'];

    private const RECHAZADO = ['rejected', 'cancelled'];

    public function iniciar(IntentoPago $intento): Inicio
    {
        try {
            $respuesta = $this->cliente()
                ->withHeaders(['X-Idempotency-Key' => $intento->referencia])
                ->post('/checkout/preferences', [
                    'items' => [[
                        'title'       => 'Pedido '.$intento->venta->numeroFormateado(),
                        'quantity'    => 1,
                        'currency_id' => 'ARS',
                        // La API pide un número; el importe tiene dos decimales y viaja exacto.
                        'unit_price'  => (float) $intento->importe,
                    ]],
                    'external_reference'   => $intento->referencia,
                    'expires'              => true,
                    'expiration_date_from' => now()->format('Y-m-d\TH:i:s.vP'),
                    'expiration_date_to'   => $intento->vence_at->format('Y-m-d\TH:i:s.vP'),
                ]);
        } catch (ConnectionException $e) {
            throw new PasarelaNoDisponible('Mercado Pago no respondió al crear la preferencia.', previous: $e);
        }

        $url = $respuesta->json('init_point');

        if (! $respuesta->successful() || ! is_string($url) || $url === '') {
            throw new PasarelaNoDisponible("Mercado Pago no creó la preferencia (HTTP {$respuesta->status()}).");
        }

        return new Inicio($url, $intento->referencia);
    }

    public function consultar(IntentoPago $intento): Consulta
    {
        if (blank(config('services.mercadopago.access_token'))) {
            return Consulta::sinRespuesta('sin_credencial');
        }

        try {
            $respuesta = $this->cliente()->get('/v1/payments/search', [
                'external_reference' => $intento->referencia,
                'sort'               => 'date_created',
                'criteria'           => 'desc',
                'limit'              => 50,
            ]);
        } catch (ConnectionException) {
            return Consulta::sinRespuesta('sin_respuesta');
        }

        $pagos = $respuesta->json('results');

        if (! $respuesta->successful() || ! is_array($pagos)) {
            return Consulta::sinRespuesta('sin_respuesta');
        }

        return $this->traducir(collect($pagos)->filter(fn ($p) => is_array($p) && ($p['currency_id'] ?? null) === 'ARS'));
    }

    /** @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $pagos  los más nuevos primero */
    private function traducir($pagos): Consulta
    {
        $con = fn (array $estados) => $pagos->first(fn (array $p) => in_array($p['status'] ?? null, $estados, true));

        if ($pago = $con(self::APROBADO)) {
            return $this->consulta('aprobado', $pago);
        }

        if ($pago = $con(self::EN_CURSO)) {
            return $this->consulta('pendiente', $pago);
        }

        // Rechazado sólo si todos lo fueron: un `refunded` o un estado nuevo no es un rechazo.
        if ($pagos->isNotEmpty() && $pagos->every(fn (array $p) => in_array($p['status'] ?? null, self::RECHAZADO, true))) {
            return $this->consulta('rechazado', $pagos->first());
        }

        // Contestó, pero nada se puede afirmar todavía: sin pagos, o con un estado que no es resultado.
        return new Consulta('pendiente', detalle: $pagos->first()['status_detail'] ?? null);
    }

    /** @param  array<string, mixed>  $pago */
    private function consulta(string $estado, array $pago): Consulta
    {
        $importe = $pago['transaction_amount'] ?? null;

        return new Consulta(
            $estado,
            isset($pago['id']) ? (string) $pago['id'] : null,
            isset($pago['status_detail']) ? (string) $pago['status_detail'] : null,
            // Llega como número JSON; con dos decimales se lee sin pérdida.
            is_numeric($importe) ? number_format((float) $importe, 2, '.', '') : null,
        );
    }

    private function cliente(): PendingRequest
    {
        $token = config('services.mercadopago.access_token');

        if (blank($token)) {
            throw new PasarelaNoDisponible('Mercado Pago no tiene credencial configurada.');
        }

        return Http::baseUrl(config('services.mercadopago.url'))
            ->withToken($token)
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout((int) config('services.mercadopago.timeout'));
    }
}
