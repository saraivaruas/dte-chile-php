<?php

declare(strict_types=1);

namespace IngeInnova\DteChile;

/** Cliente que recibe el documento. */
final class Receptor
{
    public function __construct(
        public readonly string $rut,
        public readonly string $razonSocial,
        public readonly ?string $giro = null,
        public readonly ?string $direccion = null,
        public readonly ?string $comuna = null,
        public readonly ?string $ciudad = null,
        public readonly ?string $correo = null,
    ) {
        if (!Rut::isValid($rut)) {
            throw new \InvalidArgumentException("El RUT del receptor no es válido: {$rut}");
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'rut' => $this->rut, 'razonSocial' => $this->razonSocial, 'giro' => $this->giro, 'direccion' => $this->direccion,
            'comuna' => $this->comuna, 'ciudad' => $this->ciudad, 'correo' => $this->correo,
        ], static fn (mixed $v): bool => $v !== null);
    }
}
