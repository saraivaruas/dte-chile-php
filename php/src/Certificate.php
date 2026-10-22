<?php

declare(strict_types=1);

namespace IngeInnova\DteChile;

use InvalidArgumentException;
use OpenSSLAsymmetricKey;
use RuntimeException;

/**
 * Certificado digital (.pfx/.p12) usado para firmar DTE y autenticarse ante
 * el SII. No toca disco ni base de datos — recibe los bytes crudos del
 * archivo ya en memoria, para poder usarse desde cualquier proyecto sin
 * acoplarse a cómo ese proyecto guarda los certificados.
 */
final class Certificate
{
    /** @param array{cert: string, pkey: string, extracerts?: array<int, string>} $pkcs12 */
    private function __construct(private readonly array $pkcs12)
    {
    }

    /** @throws InvalidArgumentException Si el archivo no es un .pfx/.p12 válido o la contraseña es incorrecta. */
    public static function fromPkcs12(string $rawPfx, string $password): self
    {
        if (!openssl_pkcs12_read($rawPfx, $parsed, $password)) {
            throw new InvalidArgumentException('No fue posible abrir el certificado — revise el archivo y la contraseña.');
        }

        return new self($parsed);
    }

    public function privateKey(): OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_get_private($this->pkcs12['pkey']);
        if ($key === false) {
            throw new RuntimeException('No fue posible leer la llave privada del certificado.');
        }

        return $key;
    }

    /** Certificado en formato PEM (texto, con -----BEGIN CERTIFICATE-----). */
    public function certificatePem(): string
    {
        return $this->pkcs12['cert'];
    }

    /**
     * RUT de la persona/empresa dueña del certificado, tal como el SII lo
     * codifica en el campo Subject — necesario porque el token que autentica
     * ante el SII queda asociado a este RUT, no al que uno escriba a mano.
     * Cada autoridad certificadora lo pone en un campo distinto: serialNumber
     * o CN son lo más común, pero certificados de persona natural de
     * E-CertChile lo codifican en OU (confirmado con un certificado real).
     */
    public function subjectRut(): ?string
    {
        $data = openssl_x509_parse($this->pkcs12['cert']);
        $subject = $data['subject'] ?? [];

        $raw = $subject['serialNumber'] ?? $subject['SN'] ?? null;
        if (!$raw && isset($subject['OU']) && preg_match('/^(\d{1,8}-[\dKk])$/', (string) $subject['OU'], $m)) {
            $raw = $m[1];
        }
        if (!$raw && isset($subject['CN']) && preg_match('/(\d{1,8}-[\dKk])/', (string) $subject['CN'], $m)) {
            $raw = $m[1];
        }

        return $raw ? Rut::toDteFormat((string) $raw) : null;
    }

    public function notAfter(): \DateTimeImmutable
    {
        $data = openssl_x509_parse($this->pkcs12['cert']);

        return (new \DateTimeImmutable('@' . $data['validTo_time_t']));
    }
}
