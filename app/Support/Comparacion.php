<?php

namespace App\Support;

// Un número del panel contra el mismo número del período anterior. Sin base no hay
// porcentaje: el sistema no afirma lo que no puede verificar (invariante 16).
final class Comparacion
{
    private function __construct(
        public readonly string $sentido,
        public readonly ?int $porcentaje,
    ) {
    }

    // Compara dos números que ya contó la base; acá no se consulta ni se suma nada.
    public static function entre(float $actual, float $anterior): self
    {
        if (abs($anterior) < 0.005) {
            return new self('sin_datos', null);
        }

        $sentido = match (true) {
            abs($actual - $anterior) < 0.005 => 'igual',
            $actual > $anterior              => 'mas',
            default                          => 'menos',
        };

        // Contra una base negativa —un medio por el que volvió más de lo que entró— un
        // porcentaje no significa nada: queda la palabra y el número de antes.
        $porcentaje = $anterior > 0 && $sentido !== 'igual'
            ? (int) round(abs($actual - $anterior) / $anterior * 100)
            : null;

        return new self($sentido, $porcentaje);
    }

    // La frase entera, con el período y el número de antes para que se pueda verificar.
    public function texto(string $periodo, string $anterior): string
    {
        if ($this->sentido === 'sin_datos') {
            return 'Sin datos del período anterior';
        }

        if ($this->sentido === 'igual') {
            return "Igual que {$periodo} ({$anterior})";
        }

        $palabra = $this->sentido === 'mas' ? 'más' : 'menos';

        $cuanto = match (true) {
            $this->porcentaje === null => ucfirst($palabra),
            $this->porcentaje === 0    => "Apenas {$palabra}",
            default                    => "{$this->porcentaje} % {$palabra}",
        };

        return "{$cuanto} que {$periodo} ({$anterior})";
    }

    // El ícono acompaña a la palabra, nunca la reemplaza.
    public function icono(): ?string
    {
        return match ($this->sentido) {
            'mas'   => 'bi-arrow-up-right',
            'menos' => 'bi-arrow-down-right',
            'igual' => 'bi-dash',
            default => null,
        };
    }
}