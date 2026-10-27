<?php

declare(strict_types=1);

// Factura electrónica (33): php php/examples/factura.php [--enviar]
require __DIR__ . '/_comun.php';

use IngeInnova\DteChile\Linea;

$f = crearFacturador();
$doc = $f->factura(clienteEjemplo(), [
    new Linea('Consultoría técnica', 10, 25000),
    new Linea('Capacitación exenta de IVA', 1, 50000, exento: true),
]);
guardarYEnviar($f, [$doc], $argv);
