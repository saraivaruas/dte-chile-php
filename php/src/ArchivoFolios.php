<?php

declare(strict_types=1);

namespace IngeInnova\DteChile;

/** Folios persistidos en un archivo JSON, con bloqueo de archivo para que dos procesos no entreguen el mismo folio. */
final class ArchivoFolios implements FolioStore
{
    public function __construct(private readonly string $ruta)
    {
    }

    public function siguiente(int $tipo, int $desde, int $hasta): int
    {
        $fp = fopen($this->ruta, 'c+');
        if ($fp === false || !flock($fp, LOCK_EX)) {
            throw new \RuntimeException("No se pudo abrir el archivo de folios: {$this->ruta}");
        }
        try {
            $datos = json_decode((string) stream_get_contents($fp), true) ?: [];
            $n = max((int) ($datos[(string) $tipo] ?? 0), $desde - 1) + 1;
            if ($n > $hasta) {
                throw new \RuntimeException("Se agotaron los folios del tipo {$tipo} (rango {$desde}-{$hasta}). Solicite un CAF nuevo al SII.");
            }
            $datos[(string) $tipo] = $n;
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($datos, JSON_PRETTY_PRINT) . "\n");
            fflush($fp);

            return $n;
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }
}
