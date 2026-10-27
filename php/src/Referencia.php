<?php

declare(strict_types=1);

namespace IngeInnova\DteChile;

/** Referencia a otro documento (obligatoria en notas de crédito y débito). */
final class Referencia
{
    public const ANULA = 1;
    public const CORRIGE_TEXTO = 2;
    public const CORRIGE_MONTOS = 3;

    public function __construct(
        public readonly int $tipoDocumento,
        public readonly int $folio,
        public readonly string $fecha,
        public readonly ?int $codigo = null,
        public readonly ?string $razon = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'tipoDocumento' => $this->tipoDocumento, 'folio' => $this->folio, 'fecha' => $this->fecha, 'codigo' => $this->codigo, 'razon' => $this->razon,
        ], static fn (mixed $v): bool => $v !== null);
    }
}
