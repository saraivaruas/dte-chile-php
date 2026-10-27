<?php

declare(strict_types=1);

// Guía de despacho (52), traslado 1 = operación constituye venta: php php/examples/guia_despacho.php [--enviar]
require __DIR__ . '/_comun.php';

use IngeInnova\DteChile\Linea;

$f = crearFacturador();
$doc = $f->guiaDespacho(clienteEjemplo(), [new Linea('Equipo de oficina', 2, 10000)], indicadorTraslado: 1);
guardarYEnviar($f, [$doc], $argv);
