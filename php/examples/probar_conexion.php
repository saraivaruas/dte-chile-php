<?php

declare(strict_types=1);

/**
 * Prueba la conexión con el SII sin CAF y SIN enviar ningún documento (alto nivel: Facturador::probarConexion()).
 *   php php/examples/probar_conexion.php
 * No imprime la clave ni los tokens.
 */
require __DIR__ . '/_env.php';
require __DIR__ . '/_comun.php';

cargarEnv(getenv('HOME') . '/.config/dte-chile/.env');
$r = crearFacturador(soloConexion: true)->probarConexion();
echo "Ambiente: {$r->ambiente} · titular {$r->rutTitular} · certificado vence {$r->certificadoVence->format('Y-m-d')}\n";
echo ($r->facturas ? 'OK   ' : 'FALLA') . " facturas (SOAP)\n";
echo ($r->boletas ? 'OK   ' : 'FALLA') . " boletas (REST)\n";
foreach ($r->errores as $servicio => $motivo) {
    echo "  {$servicio}: {$motivo}\n";
}
exit($r->ok() ? 0 : 1);
