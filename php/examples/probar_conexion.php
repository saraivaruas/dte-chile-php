<?php

declare(strict_types=1);

/**
 * Prueba la conexión con el SII sin necesitar CAF y SIN enviar ningún documento: pide la semilla, la firma con su certificado
 * y la canjea por un token, tanto en el servicio de facturas (SOAP) como en el de boletas (REST). Solo ambiente de certificación.
 *   php php/examples/probar_conexion.php
 * No imprime la clave ni el token completo.
 */
require __DIR__ . '/_env.php';
require __DIR__ . '/_comun.php';

use IngeInnova\DteChile\{Certificate, SiiClient};

cargarEnv(getenv('HOME') . '/.config/dte-chile/.env');
$cert = Certificate::fromPkcs12((string) file_get_contents(entorno('DTE_PFX')), entorno('DTE_PFX_CLAVE'));
$sii = new SiiClient(SiiClient::CERTIFICACION);
echo 'Certificado de ' . ($cert->subjectRut() ?? '?') . ", vence {$cert->notAfter()->format('Y-m-d')}\n";

foreach (['Facturas (SOAP, maullin.sii.cl)' => fn () => $sii->authenticate($cert), 'Boletas (REST, apicert.sii.cl)' => fn () => $sii->authenticateBoleta($cert)] as $nombre => $auth) {
    try {
        $token = $auth();
        echo "OK    {$nombre}: el SII aceptó la firma y entregó un token (" . strlen($token) . " caracteres, empieza con " . substr($token, 0, 3) . "…)\n";
    } catch (Throwable $e) {
        echo "FALLA {$nombre}: " . $e->getMessage() . "\n";
    }
}
