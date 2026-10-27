<?php

declare(strict_types=1);

namespace IngeInnova\DteChile;

/** Resultado de {@see Facturador::probarConexion()}: si el SII aceptó la firma del certificado en cada servicio. Nunca incluye tokens ni claves. */
final class ResultadoConexion
{
    /** @param array<string, string> $errores Servicio => motivo, solo de los que fallaron. */
    public function __construct(
        public readonly string $ambiente,
        public readonly bool $facturas,
        public readonly bool $boletas,
        public readonly string $rutTitular,
        public readonly \DateTimeImmutable $certificadoVence,
        public readonly array $errores = [],
    ) {
    }

    public function ok(): bool
    {
        return $this->facturas && $this->boletas;
    }
}
