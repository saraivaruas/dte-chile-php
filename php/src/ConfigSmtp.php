<?php

declare(strict_types=1);

namespace IngeInnova\DteChile;

/** Servidor SMTP para enviar el documento por correo. `cifrado`: «ssl» (puerto 465), «tls» (STARTTLS, puerto 587) o null. */
final class ConfigSmtp
{
    public function __construct(
        public readonly string $servidor,
        public readonly int $puerto,
        public readonly string $remitente,
        public readonly ?string $usuario = null,
        public readonly ?string $clave = null,
        public readonly ?string $cifrado = 'tls',
        public readonly string $nombreRemitente = '',
        public readonly int $timeout = 20,
    ) {
        if (!filter_var($remitente, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException("El correo remitente no es válido: {$remitente}");
        }
        if (!in_array($cifrado, ['ssl', 'tls', null], true)) {
            throw new \InvalidArgumentException('El cifrado debe ser «ssl», «tls» o null.');
        }
    }
}
