<?php

declare(strict_types=1);

namespace IngeInnova\DteChile;

/** Entrega el siguiente folio libre de un tipo de documento. Cada folio debe usarse una sola vez. */
interface FolioStore
{
    /** Reserva y devuelve el siguiente folio del rango [$desde, $hasta] del CAF. @throws \RuntimeException si el rango se agotó. */
    public function siguiente(int $tipo, int $desde, int $hasta): int;
}
