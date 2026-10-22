<?php

declare(strict_types=1);

namespace IngeInnova\DteChile\Tests;

use DOMDocument;
use IngeInnova\DteChile\BoletaBuilder;
use IngeInnova\DteChile\Certificate;
use IngeInnova\DteChile\DteBuilder;
use IngeInnova\DteChile\DteSigner;
use IngeInnova\DteChile\EnvioDteBuilder;
use IngeInnova\DteChile\TedSigner;

/** Boleta completa: construye, timbra y firma. @return array{0: DOMDocument, 1: array, 2: Certificate} */
function boletaFirmada(array $datos, int $tipo = 39, ?array $caf = null, ?Certificate $cert = null): array
{
    $caf ??= Fixtures::caf($tipo, 1, 100);
    $cert ??= Fixtures::certificateObject();
    $b = new BoletaBuilder();
    $doc = $b->build($tipo, $datos);
    $m = (new DteBuilder())->montoTotal($doc);
    (new DteBuilder())->attachTed($doc, (new TedSigner())->sign($caf['caf'], $tipo, $datos + ['montoTotal' => $m]));

    return [(new DteSigner())->sign($doc, $cert), $caf, $cert];
}

function datosBoleta(int $folio = 1, array $cambios = []): array
{
    return $cambios + [
        'folio' => $folio, 'fechaEmision' => '2026-09-08',
        'emisor' => ['rut' => '76.123.456-0', 'razonSocial' => 'Inge Innova SpA', 'giro' => 'Servicios', 'direccion' => 'Av. Siempre Viva 123', 'comuna' => 'Providencia', 'ciudad' => 'Santiago'],
        'items' => [['nombre' => 'Café con leche', 'cantidad' => 2, 'precioUnitario' => 2500], ['nombre' => 'Pan de pascua', 'cantidad' => 1, 'precioUnitario' => 1190]],
    ];
}

prueba('Boleta 39: el IVA se descompone del total (precios con IVA incluido) y cumple el esquema del SII', function (): void {
    [$doc, $caf] = boletaFirmada(datosBoleta());
    $v = fn (string $t) => (int) $doc->getElementsByTagName($t)->item(0)->textContent;
    igual(6190, $v('MntTotal'), 'total'); // 2×2.500 + 1.190
    igual(5202, $v('MntNeto'), 'neto');   // 6.190 / 1,19 = 5.201,68
    igual(988, $v('IVA'), 'IVA');
    igual('66666666-6', $doc->getElementsByTagName('RUTRecep')->item(0)->textContent, 'receptor genérico');
    verificaTed(bytes($doc), $caf['publicPem']);
    $env = (new EnvioDteBuilder())->buildBoleta([$doc], ['rutEmisor' => '76123456-0', 'rutEnvia' => '11111111-1', 'fechaResolucion' => '2026-01-01', 'numeroResolucion' => 0], Fixtures::certificateObject());
    validaXsd(bytes($env), 'envio_bol/EnvioBOLETA_v11.xsd');
});
prueba('Boleta 39: las firmas (documento y envío) se verifican de forma independiente', function (): void {
    [$doc, $caf, $cert] = boletaFirmada(datosBoleta(2));
    $env = (new EnvioDteBuilder())->buildBoleta([$doc], ['rutEmisor' => '76123456-0', 'rutEnvia' => '11111111-1', 'fechaResolucion' => '2026-01-01', 'numeroResolucion' => 0], $cert);
    igual(2, verificaXmlDsig(bytes($env), $cert->certificatePem()), 'firmas (Documento + SetDTE)');
});
prueba('Boleta 39: con preciosNetos el IVA se suma', function (): void {
    [$doc] = boletaFirmada(datosBoleta(3, ['preciosNetos' => true, 'items' => [['nombre' => 'Servicio', 'monto' => 10000]]]));
    $v = fn (string $t) => (int) $doc->getElementsByTagName($t)->item(0)->textContent;
    igual([10000, 1900, 11900], [$v('MntNeto'), $v('IVA'), $v('MntTotal')], 'neto, IVA, total');
    igual('2', $doc->getElementsByTagName('IndMntNeto')->item(0)->textContent, 'IndMntNeto');
});
prueba('Boleta 41 (exenta): todo es exento y sin IVA', function (): void {
    [$doc, , $cert] = boletaFirmada(datosBoleta(1, ['items' => [['nombre' => 'Libro', 'cantidad' => 1, 'precioUnitario' => 15000]]]), 41);
    $v = fn (string $t) => $doc->getElementsByTagName($t)->length ? (int) $doc->getElementsByTagName($t)->item(0)->textContent : null;
    igual([15000, 15000, null, null], [$v('MntExe'), $v('MntTotal'), $v('IVA'), $v('MntNeto')], 'exento, total, IVA, neto');
    $env = (new EnvioDteBuilder())->buildBoleta([$doc], ['rutEmisor' => '76123456-0', 'rutEnvia' => '11111111-1', 'fechaResolucion' => '2026-01-01', 'numeroResolucion' => 0], $cert);
    validaXsd(bytes($env), 'envio_bol/EnvioBOLETA_v11.xsd');
});
prueba('Boleta: mezcla de ítems afectos y exentos, y descuento global', function (): void {
    [$doc] = boletaFirmada(datosBoleta(1, [
        'items' => [['nombre' => 'Afecto', 'monto' => 11900], ['nombre' => 'Exento', 'monto' => 5000, 'exento' => true]],
        'descuentosRecargos' => [['tipo' => 'D', 'valorTipo' => '%', 'valor' => 10]],
    ]));
    $v = fn (string $t) => (int) $doc->getElementsByTagName($t)->item(0)->textContent;
    igual([9000, 1710, 5000], [$v('MntNeto'), $v('IVA'), $v('MntExe')], 'neto, IVA, exento'); // el descuento global sin IndExeDR solo afecta a lo afecto: 10.710/1,19 = 9.000
    igual(15710, $v('MntTotal'), 'total');
});
prueba('Boleta: un EnvioBOLETA rechaza documentos que no son boletas y BoletaBuilder rechaza otros tipos', function (): void {
    [$fact] = dteFirmado(Fixtures::datosFactura());
    lanza(fn () => (new EnvioDteBuilder())->buildBoleta([$fact], ['rutEmisor' => '76123456-0', 'rutEnvia' => '11111111-1', 'fechaResolucion' => '2026-01-01', 'numeroResolucion' => 0], Fixtures::certificateObject()), 'solo admite boletas');
    lanza(fn () => (new BoletaBuilder())->build(33, datosBoleta()), 'DteBuilder');
    lanza(fn () => (new BoletaBuilder())->build(39, datosBoleta(1, ['indicadorServicio' => 9])), 'no es un valor permitido');
});
