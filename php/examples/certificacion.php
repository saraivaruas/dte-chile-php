<?php

declare(strict_types=1);

/**
 * Emite un documento de cada tipo que su CAF permita, para el proceso de certificación del SII.
 *   php php/examples/certificacion.php            -> solo genera y guarda en ./salida (no envía nada)
 *   php php/examples/certificacion.php --enviar   -> además sube los envíos al ambiente de CERTIFICACIÓN (maullin.sii.cl / apicert.sii.cl)
 *
 * Importante: el SII exige que el contenido de los documentos sea el de su «set de pruebas» (se descarga en el sitio de certificación,
 * con datos y casos fijos). Este script sirve para comprobar certificado, CAF, firma, envío y estado; para el set oficial cambie
 * las líneas y los receptores de abajo por los que indique el SII. En producción el script se niega a enviar.
 */
require __DIR__ . '/_comun.php';

use IngeInnova\DteChile\{DocumentType, Linea, Referencia};

if (entorno('DTE_AMBIENTE', 'certificacion') !== 'certificacion') {
    fwrite(STDERR, "Este script solo envía a certificación.\n");
    exit(2);
}
$f = crearFacturador();
$cliente = clienteEjemplo();
$ref = static fn (int $tipo, int $folio): Referencia => new Referencia($tipo, $folio, date('Y-m-d'), Referencia::CORRIGE_MONTOS, 'Corrige montos');
$cafs = $f->tiposDisponibles();

$facturas = $boletas = [];
foreach ($cafs as $tipo) {
    match ($tipo) {
        DocumentType::FACTURA_ELECTRONICA => $facturas[] = $f->factura($cliente, [new Linea('Servicio de ingeniería', 10, 25000)]),
        DocumentType::FACTURA_NO_AFECTA_EXENTA => $facturas[] = $f->facturaExenta($cliente, [new Linea('Capacitación', 1, 50000, true)]),
        DocumentType::NOTA_CREDITO => $facturas[] = $f->notaCredito($cliente, [new Linea('Servicio de ingeniería', 1, 25000)], [$ref(33, 1)]),
        DocumentType::NOTA_DEBITO => $facturas[] = $f->notaDebito($cliente, [new Linea('Intereses', 1, 5000)], [$ref(33, 1)]),
        DocumentType::GUIA_DESPACHO => $facturas[] = $f->guiaDespacho($cliente, [new Linea('Equipo', 2, 10000)], 1),
        DocumentType::BOLETA_ELECTRONICA => $boletas[] = $f->boleta([new Linea('Café', 2, 2500)]),
        DocumentType::BOLETA_NO_AFECTA_EXENTA => $boletas[] = $f->boletaExenta([new Linea('Libro', 1, 9990, true)]),
        default => null,
    };
}
if ($facturas !== []) {
    guardarYEnviar($f, $facturas, $argv);
}
if ($boletas !== []) {
    guardarYEnviar($f, $boletas, $argv);
}
if ($facturas === [] && $boletas === []) {
    fwrite(STDERR, "No hay CAF en DTE_CAF_DIR para ningún tipo soportado.\n");
    exit(1);
}
