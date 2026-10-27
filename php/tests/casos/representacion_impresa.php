<?php

declare(strict_types=1);

namespace IngeInnova\DteChile\Tests;

use IngeInnova\DteChile\Linea;
use IngeInnova\DteChile\Referencia;

/** Valida la estructura del PDF: cabecera, tabla xref con desplazamientos exactos y objetos contiguos. */
function verificaPdf(string $pdf): void
{
    afirma(str_starts_with($pdf, '%PDF-1.4'), 'Debe empezar con la cabecera PDF.');
    afirma(str_ends_with(rtrim($pdf), '%%EOF'), 'Debe terminar con %%EOF.');
    afirma((bool) preg_match('/startxref\n(\d+)\n%%EOF/', $pdf, $m), 'Falta startxref.');
    igual('xref', substr($pdf, (int) $m[1], 4), 'startxref apunta a la tabla xref');
    preg_match('/xref\n0 (\d+)\n/', $pdf, $x);
    $total = (int) $x[1];
    preg_match_all('/(\d{10}) 00000 n \n/', $pdf, $offs);
    igual($total - 1, count($offs[1]), 'una entrada de la tabla por objeto');
    foreach ($offs[1] as $i => $o) {
        afirma(str_starts_with(substr($pdf, (int) $o, 12), ($i + 1) . ' 0 obj'), 'El objeto ' . ($i + 1) . ' no está en su desplazamiento.');
    }
    preg_match_all('/\/Length (\d+) >>\nstream\n/', $pdf, $len, PREG_OFFSET_CAPTURE);
    foreach ($len[1] as $i => [$largo, $pos]) {
        $ini = $pos + strlen("{$largo} >>\nstream\n") + strlen('/Length ') - strlen('/Length ');
        $flujo = substr($pdf, strpos($pdf, "stream\n", $pos) + 7, (int) $largo);
        afirma(str_ends_with($flujo, "\n") && substr($pdf, strpos($pdf, "stream\n", $pos) + 7 + (int) $largo, 9) === 'endstream', 'El largo del flujo ' . $i . ' no coincide.');
    }
}

/** Reconstruye la matriz PDF417 a partir de los rectángulos dibujados en el PDF. @return array{0: array, 1: int} */
function matrizDelPdf(string $pdf): array
{
    preg_match_all('/^([\d.]+) ([\d.]+) ([\d.]+) ([\d.]+) re$/m', $pdf, $r, PREG_SET_ORDER);
    afirma(count($r) > 50, 'No hay rectángulos del timbre.');
    $w = min(array_map(static fn ($m) => (float) $m[3], $r));
    $h = (float) $r[0][4];
    $x0 = min(array_map(static fn ($m) => (float) $m[1], $r));
    $yMax = max(array_map(static fn ($m) => (float) $m[2], $r));
    $filas = [];
    foreach ($r as $m) {
        $fila = (int) round(($yMax - (float) $m[2]) / $h);
        $col = (int) round(((float) $m[1] - $x0) / $w);
        $n = (int) round((float) $m[3] / $w);
        for ($i = 0; $i < $n; $i++) {
            $filas[$fila][$col + $i] = true;
        }
    }
    $ancho = max(array_map(static fn ($f) => max(array_keys($f)), $filas)) + 1;
    $matriz = [];
    ksort($filas);
    foreach ($filas as $f) {
        $linea = [];
        for ($c = 0; $c < $ancho; $c++) {
            $linea[] = isset($f[$c]);
        }
        $matriz[] = $linea;
    }

    return [$matriz, (int) (($ancho - 69) / 17)];
}

prueba('PDF: la factura sale como un PDF válido, con los textos del SII y los montos correctos', function (): void {
    [$f] = facturador([33]);
    $doc = $f->factura(cliente(), [new Linea('Consultoría técnica', 10, 25000), new Linea('Capacitación', 1, 50000, true)], ['fechaEmision' => '2026-09-08']);
    $pdf = $f->pdf($doc, ['cedible' => true, 'unidadSii' => 'Santiago Centro']);
    verificaPdf($pdf);
    foreach (['R.U.T.: 76.123.456-0', 'S.I.I. - SANTIAGO CENTRO', 'Verifique documento: www.sii.cl', 'Res. 0 de 2026', '$ 250.000', '$ 47.500', '$ 50.000', 'TOTAL', 'CEDIBLE', '8 de septiembre de 2026', 'Cliente de Prueba Ltda'] as $texto) {
        afirma(str_contains($pdf, $texto), "Falta «{$texto}» en el PDF.");
    }
    afirma(str_contains($pdf, "FACTURA ELECTR\xD3NICA"), 'Falta el tipo de documento (en Latin-1).');
    afirma(str_contains($pdf, '$ 347.500'), 'Falta el total (250.000 neto + 47.500 IVA + 50.000 exento).');
});
prueba('PDF: el timbre PDF417 dibujado en el PDF se decodifica byte a byte igual al TED del documento', function (): void {
    [$f] = facturador([33]);
    $doc = $f->factura(cliente(), [new Linea('Café con leche y ñandú & «cía»', 2, 2500)], ['fechaEmision' => '2026-09-08']);
    [$matriz, $columnas] = matrizDelPdf($f->pdf($doc));
    igual($doc->ted, pdf417Decode($matriz, $columnas), 'bytes del timbre leídos desde el PDF');
});
prueba('PDF: nota de crédito con referencia, boleta sin receptor y documento con muchas líneas (varias páginas)', function (): void {
    [$f] = facturador([61, 39, 33]);
    $nc = $f->notaCredito(cliente(), [new Linea('Consultoría', 1, 25000)], [new Referencia(33, 7, '2026-09-08', Referencia::ANULA, 'Anula factura')], ['fechaEmision' => '2026-09-09']);
    $pdf = $f->pdf($nc);
    verificaPdf($pdf);
    afirma(str_contains($pdf, 'Referencias') && str_contains($pdf, 'Anula documento'), 'Debe listar la referencia.');
    $b = $f->pdf($f->boleta([new Linea('Café', 2, 2500)], null, ['fechaEmision' => '2026-09-08']));
    verificaPdf($b);
    afirma(str_contains($b, 'Consumidor final'), 'La boleta sin receptor dice «Consumidor final».');
    $muchas = array_map(static fn (int $i): Linea => new Linea("Producto número {$i} con una descripción larga para ocupar espacio", 1, 1000 + $i), range(1, 55));
    $grande = $f->pdf($f->factura(cliente(), $muchas, ['fechaEmision' => '2026-09-08']));
    verificaPdf($grande);
    afirma(substr_count($grande, '/Type /Page ') >= 2, 'Con 55 líneas debe usar más de una página.');
});
prueba('PDF: los paréntesis y la barra invertida se escapan y lo que no existe en Latin-1 pasa a «?»', function (): void {
    [$f] = facturador([33]);
    $pdf = $f->pdf($f->factura(cliente(), [new Linea('Servicio (mensual) \ especial', 1, 1000)], ['fechaEmision' => '2026-09-08']));
    verificaPdf($pdf);
    igual("Caf\xE9 ?", \IngeInnova\DteChile\PdfEscritor::codificar('Café ★'), 'Fuera de Latin-1 pasa a «?»');
    afirma(str_contains($pdf, 'Servicio \\(mensual\\) \\\\ especial'), 'Paréntesis y barra deben escaparse.');
});
