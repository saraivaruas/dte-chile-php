<?php

declare(strict_types=1);

namespace IngeInnova\DteChile;

/** Resultado de emitir y enviar un documento: qué se emitió, en qué estado está y, si algo falló, qué hacer. */
final class ResultadoEmision
{
    public function __construct(
        public readonly DocumentoEmitido $documento,
        public readonly EstadoDocumento $estado,
        /** Número de seguimiento del SII (null si no llegó a enviarse). */
        public readonly ?string $trackId = null,
        /** Explicación en lenguaje de usuario (vacía si todo va bien). */
        public readonly string $mensaje = '',
        /** Cuántas veces se intentó enviar. */
        public readonly int $intentos = 0,
        /** Mensaje original del SII o de la red, para registro técnico. */
        public readonly ?string $detalleTecnico = null,
    ) {
    }

    public function exitoso(): bool
    {
        return $this->estado === EstadoDocumento::Aceptado || $this->estado === EstadoDocumento::ConReparos || $this->estado === EstadoDocumento::Enviado;
    }

    public function conEstado(EstadoDocumento $estado, string $mensaje = '', ?string $detalle = null): self
    {
        return new self($this->documento, $estado, $this->trackId, $mensaje, $this->intentos, $detalle);
    }
}
