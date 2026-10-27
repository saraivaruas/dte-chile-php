<?php

declare(strict_types=1);

namespace IngeInnova\DteChile;

/** Folios en memoria: sirve para pruebas; al terminar el proceso se pierde el avance. */
final class MemoriaFolios implements FolioStore
{
    /** @var array<int, int> */
    private array $ultimo = [];

    public function siguiente(int $tipo, int $desde, int $hasta): int
    {
        $n = max($this->ultimo[$tipo] ?? 0, $desde - 1) + 1;
        if ($n > $hasta) {
            throw new \RuntimeException("Se agotaron los folios del tipo {$tipo} (rango {$desde}-{$hasta}). Solicite un CAF nuevo al SII.");
        }

        return $this->ultimo[$tipo] = $n;
    }
}
