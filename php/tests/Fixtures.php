<?php

declare(strict_types=1);

namespace IngeInnova\DteChile\Tests;

use IngeInnova\DteChile\Caf;
use IngeInnova\DteChile\Certificate;

/**
 * Datos de prueba generados al vuelo, sin archivos ni secretos reales: un certificado autofirmado (.pfx) y un CAF falso con su propio par de llaves RSA.
 * El CAF falso NO lleva una firma real del SII (FRMA es relleno): sirve para probar el timbre y las firmas de la librería, no la aceptación del SII.
 */
final class Fixtures
{
    public const PASSWORD = 'clave-de-prueba';
    public const RUT_EMISOR = '76123456-0';
    public const RUT_RECEPTOR = '77111222-6';

    /** @return array{pfx: string, certPem: string} */
    public static function certificate(string $rutEnSerialNumber = '11111111-1'): array
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['countryName' => 'CL', 'commonName' => 'Persona de Prueba', 'serialNumber' => $rutEnSerialNumber], $key, ['digest_alg' => 'sha256']);
        $x509 = openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256']);
        openssl_x509_export($x509, $certPem);
        openssl_pkcs12_export($x509, $pfx, $key, self::PASSWORD);

        return ['pfx' => $pfx, 'certPem' => $certPem];
    }

    public static function certificateObject(): Certificate
    {
        return Certificate::fromPkcs12(self::certificate()['pfx'], self::PASSWORD);
    }

    /** CAF falso (tipo $tipo, folios $desde-$hasta) y la llave pública para verificar los timbres de forma independiente. */
    public static function caf(int $tipo = 33, int $desde = 1, int $hasta = 100, bool $conIndentacion = true): array
    {
        $key = openssl_pkey_new(['private_key_bits' => 1024, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $privatePem);
        $details = openssl_pkey_get_details($key);
        $publicPem = $details['key'];
        $m = base64_encode($details['rsa']['n']);
        $e = base64_encode($details['rsa']['e']);
        $nl = $conIndentacion ? "\n      " : '';

        $xml = "<?xml version=\"1.0\"?>\n<AUTORIZACION>\n  <CAF version=\"1.0\">\n    <DA>{$nl}<RE>" . self::RUT_EMISOR . "</RE>{$nl}<RS>EMPRESA DE PRUEBA SPA</RS>{$nl}<TD>{$tipo}</TD>"
            . "{$nl}<RNG><D>{$desde}</D><H>{$hasta}</H></RNG>{$nl}<FA>2026-01-01</FA>{$nl}<RSAPK><M>{$m}</M><E>{$e}</E></RSAPK>{$nl}<IDK>100</IDK>\n    </DA>\n"
            . "    <FRMA algoritmo=\"SHA1withRSA\">" . base64_encode(str_repeat('F', 64)) . "</FRMA>\n  </CAF>\n"
            . "  <RSASK>{$privatePem}</RSASK>\n  <RSAPUBK>{$publicPem}</RSAPUBK>\n</AUTORIZACION>\n";

        return ['xml' => $xml, 'caf' => Caf::fromXml($xml), 'publicPem' => $publicPem];
    }

    /** @return array<string, mixed> Datos de una factura típica. */
    public static function datosFactura(int $folio = 1, array $cambios = []): array
    {
        // Los ítems se reemplazan completos (array_replace_recursive los mezclaría por posición).
        $items = $cambios['items'] ?? [['nombre' => 'Consultoría técnica', 'cantidad' => 10, 'precioUnitario' => 25000]];
        unset($cambios['items']);

        $datos = array_replace_recursive([
            'folio' => $folio,
            'fechaEmision' => '2026-09-08',
            'emisor' => ['rut' => '76.123.456-0', 'razonSocial' => 'Inge Innova SpA', 'giro' => 'Servicios de Ingeniería', 'actividadEconomica' => 711001, 'direccion' => 'Av. Siempre Viva 123', 'comuna' => 'Providencia', 'ciudad' => 'Santiago'],
            'receptor' => ['rut' => '77.111.222-6', 'razonSocial' => 'Cliente de Prueba Ltda', 'giro' => 'Comercio', 'direccion' => 'Calle Falsa 456', 'comuna' => 'Las Condes'],
        ], $cambios);
        $datos['items'] = $items;

        return $datos;
    }
}
