<?php

declare(strict_types=1);

namespace IngeInnova\DteChile;

/** Resultado de subir un envío al SII. El `trackId` sirve para consultar el estado después. */
final class ResultadoEnvio
{
    public function __construct(public readonly string $trackId, public readonly int $documentos)
    {
    }
}
