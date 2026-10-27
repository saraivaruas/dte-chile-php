<?php

declare(strict_types=1);

namespace IngeInnova\DteChile;

/** Empresa que emite los documentos. */
final class Emisor
{
    /** @param int|int[] $actividadEconomica Código(s) de actividad económica del SII (hasta 4). */
    public function __construct(
        public readonly string $rut,
        public readonly string $razonSocial,
        public readonly string $giro,
        public readonly int|array $actividadEconomica,
        public readonly ?string $direccion = null,
        public readonly ?string $comuna = null,
        public readonly ?string $ciudad = null,
        public readonly ?string $correo = null,
        public readonly ?string $telefono = null,
    ) {
        if (!Rut::isValid($rut)) {
            throw new \InvalidArgumentException("El RUT del emisor no es válido: {$rut}");
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'rut' => $this->rut, 'razonSocial' => $this->razonSocial, 'giro' => $this->giro, 'actividadEconomica' => $this->actividadEconomica,
            'direccion' => $this->direccion, 'comuna' => $this->comuna, 'ciudad' => $this->ciudad, 'correo' => $this->correo, 'telefono' => $this->telefono,
        ], static fn (mixed $v): bool => $v !== null);
    }
}
