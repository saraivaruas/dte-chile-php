<?php

declare(strict_types=1);

// Boleta electrónica (39): precios con IVA incluido, sin receptor. php php/examples/boleta.php [--enviar]
require __DIR__ . '/_comun.php';

use IngeInnova\DteChile\Linea;

$f = crearFacturador();
$doc = $f->boleta([new Linea('Café', 2, 2500), new Linea('Pan de pascua', 1, 1190)]);
guardarYEnviar($f, [$doc], $argv);
file_put_contents('salida/timbre.svg', $doc->timbreSvg());
echo "Timbre PDF417 -> salida/timbre.svg\n";
