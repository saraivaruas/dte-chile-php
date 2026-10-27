<?php

declare(strict_types=1);

namespace IngeInnova\DteChile;

/** Estado de un documento en su camino al SII, ya interpretado (sin códigos del SII). */
enum EstadoDocumento: string
{
    /** Emitido y firmado, pero no llegó al SII (error de red o SII caído). Se puede reenviar con {@see Facturador::reenviar()}. */
    case Emitido = 'emitido';
    /** El SII lo recibió y lo está procesando: consulte de nuevo en unos minutos. */
    case Enviado = 'enviado';
    case Aceptado = 'aceptado';
    case ConReparos = 'con_reparos';
    case Rechazado = 'rechazado';

    /** Ya no cambiará: no hace falta consultar más. */
    public function esFinal(): bool
    {
        return in_array($this, [self::Aceptado, self::ConReparos, self::Rechazado], true);
    }
}
