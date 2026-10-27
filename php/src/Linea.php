<?php

declare(strict_types=1);

namespace IngeInnova\DteChile;

/** Una línea de detalle. En boletas el precio incluye IVA; en el resto es neto. */
final class Linea
{
    public function __construct(
        public readonly string $nombre,
        public readonly float|int $cantidad,
        public readonly float|int $precioUnitario,
        public readonly bool $exento = false,
        public readonly ?float $descuentoPct = null,
        public readonly ?string $descripcion = null,
        public readonly ?string $unidad = null,
    ) {
        if ($nombre === '') {
            throw new \InvalidArgumentException('La línea necesita un nombre.');
        }
        if ($cantidad <= 0) {
            throw new \InvalidArgumentException("La cantidad debe ser mayor que cero (línea «{$nombre}»).");
        }
        if ($precioUnitario < 0) {
            throw new \InvalidArgumentException("El precio no puede ser negativo (línea «{$nombre}»).");
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'nombre' => $this->nombre, 'cantidad' => $this->cantidad, 'precioUnitario' => $this->precioUnitario,
            'exento' => $this->exento ? true : null, 'descuentoPct' => $this->descuentoPct, 'descripcion' => $this->descripcion, 'unidad' => $this->unidad,
        ], static fn (mixed $v): bool => $v !== null);
    }
}
