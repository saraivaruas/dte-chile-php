<?php

declare(strict_types=1);

// Nota de crédito (61) que anula la factura folio 1: php php/examples/nota_credito.php [--enviar]
require __DIR__ . '/_comun.php';

use IngeInnova\DteChile\{Linea, Referencia};

$f = crearFacturador();
$doc = $f->notaCredito(
    clienteEjemplo(),
    [new Linea('Consultoría técnica', 10, 25000)],
    [new Referencia(tipoDocumento: 33, folio: 1, fecha: date('Y-m-d'), codigo: Referencia::ANULA, razon: 'Anula factura')],
);
guardarYEnviar($f, [$doc], $argv);
