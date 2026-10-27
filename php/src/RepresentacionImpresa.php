<?php

declare(strict_types=1);

namespace IngeInnova\DteChile;

use DOMDocument;
use DOMElement;

/**
 * Representación impresa (PDF, tamaño Carta) de un documento tributario electrónico ya firmado, con el timbre PDF417 y las leyendas que pide el SII:
 * recuadro rojo con RUT, tipo y folio, unidad del SII, datos del emisor y del receptor, detalle, totales y «Timbre Electrónico SII ... Verifique documento: www.sii.cl».
 *
 * Lee todo del XML del documento (sirve para facturas, notas, guías y boletas) y no necesita dependencias.
 *
 * Opciones:
 *   unidadSii         texto de «S.I.I. - ...» (por defecto SANTIAGO)
 *   resolucionNumero  y resolucionFecha (AAAA-MM-DD): para la leyenda «Res. N° de AAAA» del timbre
 *   cedible           true agrega la leyenda «CEDIBLE» (solo facturas)
 *   logo              logotipo de la empresa: ruta de un archivo JPEG o sus bytes (se ubica arriba a la izquierda, sin deformarlo)
 */
final class RepresentacionImpresa
{
    private const ROJO = [0.85, 0.0, 0.0];
    private const GRIS = [0.92, 0.92, 0.92];
    private const MARGEN = 14.0;
    private const COL_DESC = 14.0;
    private const MESES = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

    private PdfEscritor $pdf;

    /** @param array<string, mixed> $opciones */
    public function generar(DocumentoEmitido $documento, array $opciones = []): string
    {
        $d = $this->leer($documento->xml);
        $this->pdf = new PdfEscritor();
        $this->pdf->nuevaPagina();
        $y = $this->encabezado($d, (string) ($opciones['unidadSii'] ?? 'SANTIAGO'), $this->cargarLogo($opciones['logo'] ?? null));
        $y = $this->receptor($d, $y);
        $y = $this->referencias($d, $y);
        $y = $this->detalle($d, $y);
        $this->pie($d, $documento, $y, $opciones);
        $titulo = DocumentType::label($d['tipo']) . ' N° ' . $d['folio'];

        return $this->pdf->generar($titulo);
    }

    /** @return array<string, mixed> */
    private function leer(DOMDocument $xml): array
    {
        $doc = $xml->getElementsByTagName('Documento')->item(0);
        if (!$doc instanceof DOMElement) {
            throw new \InvalidArgumentException('El XML no contiene un <Documento>.');
        }
        $v = static function (?DOMElement $padre, string ...$tags): string {
            foreach ($tags as $t) {
                $n = $padre?->getElementsByTagName($t)->item(0);
                if ($n !== null && trim($n->textContent) !== '') {
                    return trim($n->textContent);
                }
            }

            return '';
        };
        $enc = $doc->getElementsByTagName('Encabezado')->item(0);
        $idDoc = $enc?->getElementsByTagName('IdDoc')->item(0);
        $emisor = $enc?->getElementsByTagName('Emisor')->item(0);
        $receptor = $enc?->getElementsByTagName('Receptor')->item(0);
        $totales = $enc?->getElementsByTagName('Totales')->item(0);
        $lineas = [];
        foreach ($doc->getElementsByTagName('Detalle') as $det) {
            $lineas[] = [
                'nombre' => $v($det, 'NmbItem'), 'descripcion' => $v($det, 'DscItem'), 'cantidad' => $v($det, 'QtyItem'), 'unidad' => $v($det, 'UnmdItem'),
                'precio' => $v($det, 'PrcItem'), 'descuentoPct' => $v($det, 'DescuentoPct'), 'monto' => $v($det, 'MontoItem'), 'exento' => $v($det, 'IndExe'),
            ];
        }
        $refs = [];
        foreach ($doc->getElementsByTagName('Referencia') as $r) {
            $refs[] = ['tipo' => $v($r, 'TpoDocRef'), 'folio' => $v($r, 'FolioRef'), 'fecha' => $v($r, 'FchRef'), 'razon' => $v($r, 'RazonRef'), 'codigo' => $v($r, 'CodRef')];
        }

        return [
            'tipo' => (int) $v($idDoc, 'TipoDTE'), 'folio' => $v($idDoc, 'Folio'), 'fecha' => $v($idDoc, 'FchEmis'), 'vence' => $v($idDoc, 'FchVenc'), 'formaPago' => $v($idDoc, 'FmaPago'),
            'emisor' => [
                'rut' => $v($emisor, 'RUTEmisor'), 'razon' => $v($emisor, 'RznSoc', 'RznSocEmisor'), 'giro' => $v($emisor, 'GiroEmis', 'GiroEmisor'),
                'direccion' => $v($emisor, 'DirOrigen'), 'comuna' => $v($emisor, 'CmnaOrigen'), 'ciudad' => $v($emisor, 'CiudadOrigen'),
                'correo' => $v($emisor, 'CorreoEmisor'), 'telefono' => $v($emisor, 'Telefono'),
            ],
            'receptor' => [
                'rut' => $v($receptor, 'RUTRecep'), 'razon' => $v($receptor, 'RznSocRecep'), 'giro' => $v($receptor, 'GiroRecep'),
                'direccion' => $v($receptor, 'DirRecep'), 'comuna' => $v($receptor, 'CmnaRecep'), 'ciudad' => $v($receptor, 'CiudadRecep'),
            ],
            'totales' => [
                'neto' => $v($totales, 'MntNeto'), 'exento' => $v($totales, 'MntExe'), 'tasaIva' => $v($totales, 'TasaIVA'), 'iva' => $v($totales, 'IVA'), 'total' => $v($totales, 'MntTotal'),
            ],
            'lineas' => $lineas, 'referencias' => $refs,
        ];
    }

    /** @return array{0: int, 1: int, 2: int}|null */
    private function cargarLogo(mixed $logo): ?array
    {
        if ($logo === null || $logo === '') {
            return null;
        }
        $bytes = is_string($logo) && !str_starts_with($logo, "\xFF\xD8") && is_file($logo) ? (string) file_get_contents($logo) : (string) $logo;

        return $this->pdf->agregarJpeg($bytes);
    }

    /**
     * @param array<string, mixed> $d
     * @param array{0: int, 1: int, 2: int}|null $logo
     */
    private function encabezado(array $d, string $unidadSii, ?array $logo = null): float
    {
        $p = $this->pdf;
        $e = $d['emisor'];
        $izq = self::MARGEN;
        if ($logo !== null) {
            $p->imagen($logo[0], $logo[1], $logo[2], self::MARGEN, self::MARGEN + 1, 34.0, 24.0);
            $izq = self::MARGEN + 38.0;
        }
        $boxW = 72.0;
        $boxX = PdfEscritor::ANCHO_MM - self::MARGEN - $boxW;
        $anchoEmisor = $boxX - $izq - 6;

        // Emisor
        $y = self::MARGEN + 6;
        foreach ($p->envolver(mb_strtoupper($e['razon']), $anchoEmisor, 13, true) as $l) {
            $p->texto($izq, $y, $l, 13, true);
            $y += 5.6;
        }
        $y += 1;
        $linea = static fn (string ...$partes): string => implode(', ', array_filter($partes, static fn (string $s): bool => $s !== ''));
        foreach ([
            $e['giro'] !== '' ? 'Giro: ' . $e['giro'] : '',
            $linea($e['direccion'], $e['comuna'], $e['ciudad']),
            $linea($e['telefono'] !== '' ? 'Tel.: ' . $e['telefono'] : '', $e['correo']),
        ] as $texto) {
            if ($texto === '') {
                continue;
            }
            foreach ($p->envolver($texto, $anchoEmisor, 8.5) as $l) {
                $p->texto($izq, $y, $l, 8.5);
                $y += 4.1;
            }
        }

        // Recuadro rojo con RUT, tipo y folio
        $boxY = self::MARGEN;
        $boxH = 33.0;
        $p->rectangulo($boxX, $boxY, $boxW, $boxH, 0.6, self::ROJO);
        $cx = $boxX + $boxW / 2;
        $p->textoCentrado($cx, $boxY + 8.5, 'R.U.T.: ' . $this->rut($e['rut']), 12, true, self::ROJO);
        $tipo = mb_strtoupper(DocumentType::label($d['tipo']));
        $ly = $boxY + 15.5;
        foreach ($p->envolver($tipo, $boxW - 6, 11, true) as $l) {
            $p->textoCentrado($cx, $ly, $l, 11, true, self::ROJO);
            $ly += 5;
        }
        $p->textoCentrado($cx, $boxY + $boxH - 3.5, 'N° ' . $d['folio'], 12, true, self::ROJO);
        $p->textoCentrado($cx, $boxY + $boxH + 5.5, 'S.I.I. - ' . mb_strtoupper($unidadSii), 9, true, self::ROJO);

        return max($y, $boxY + $boxH + 9) + 4;
    }

    /** @param array<string, mixed> $d */
    private function receptor(array $d, float $y): float
    {
        $p = $this->pdf;
        $r = $d['receptor'];
        $x = self::MARGEN;
        $ancho = PdfEscritor::ANCHO_MM - 2 * self::MARGEN;
        $generico = $r['rut'] === BoletaBuilder::RUT_GENERICO;
        $filas = [['Fecha de emisión', $this->fecha($d['fecha'])]];
        if ($d['vence'] !== '') {
            $filas[] = ['Fecha de vencimiento', $this->fecha($d['vence'])];
        }
        $filas[] = ['Señor(es)', $generico && $r['razon'] === '' ? 'Consumidor final' : $r['razon']];
        if (!$generico) {
            $filas[] = ['R.U.T.', $this->rut($r['rut'])];
        }
        foreach ([['Giro', $r['giro']], ['Dirección', implode(', ', array_filter([$r['direccion'], $r['comuna'], $r['ciudad']]))]] as $f) {
            if ($f[1] !== '') {
                $filas[] = $f;
            }
        }
        $fp = ['1' => 'Contado', '2' => 'Crédito', '3' => 'Sin costo'][$d['formaPago']] ?? '';
        if ($fp !== '') {
            $filas[] = ['Forma de pago', $fp];
        }
        $alto = count($filas) * 4.8 + 3;
        $p->rectangulo($x, $y, $ancho, $alto, 0.25);
        $fy = $y + 5;
        foreach ($filas as [$k, $val]) {
            $p->texto($x + 2.5, $fy, $k, 8.5, true);
            $p->texto($x + 38, $fy, $val, 8.5);
            $fy += 4.8;
        }

        return $y + $alto + 4;
    }

    /** @param array<string, mixed> $d */
    private function referencias(array $d, float $y): float
    {
        if ($d['referencias'] === []) {
            return $y;
        }
        $p = $this->pdf;
        $p->texto(self::MARGEN, $y + 3.5, 'Referencias', 8.5, true);
        $y += 6.5;
        foreach ($d['referencias'] as $r) {
            $tipo = ctype_digit($r['tipo']) ? DocumentType::label((int) $r['tipo']) : $r['tipo'];
            $cod = ['1' => 'Anula documento', '2' => 'Corrige texto', '3' => 'Corrige montos'][$r['codigo']] ?? '';
            $texto = trim("{$tipo} N° {$r['folio']} del " . $this->fecha($r['fecha']) . ($cod !== '' ? " - {$cod}" : '') . ($r['razon'] !== '' ? ": {$r['razon']}" : ''));
            foreach ($p->envolver($texto, PdfEscritor::ANCHO_MM - 2 * self::MARGEN, 8.5) as $l) {
                $p->texto(self::MARGEN, $y + 3, $l, 8.5);
                $y += 4.1;
            }
        }

        return $y + 3;
    }

    /** @param array<string, mixed> $d */
    private function detalle(array $d, float $y): float
    {
        $p = $this->pdf;
        $x0 = self::MARGEN;
        $x1 = PdfEscritor::ANCHO_MM - self::MARGEN;
        // columnas (bordes derechos): cantidad, precio, descuento, valor
        $cCant = $x1 - 78;
        $cPrecio = $x1 - 52;
        $cDesc = $x1 - 30;
        $limite = PdfEscritor::ALTO_MM - 78.0; // reserva el pie (totales y timbre) en la última página
        $encabezado = function (float $yy) use ($p, $x0, $x1, $cCant, $cPrecio, $cDesc): float {
            $p->rellenar($x0, $yy, $x1 - $x0, 6.5, self::GRIS);
            $p->rectangulo($x0, $yy, $x1 - $x0, 6.5, 0.25);
            $p->texto($x0 + 2, $yy + 4.4, 'Descripción', 8.5, true);
            $p->textoDerecha($cCant, $yy + 4.4, 'Cant.', 8.5, true);
            $p->textoDerecha($cPrecio, $yy + 4.4, 'Precio', 8.5, true);
            $p->textoDerecha($cDesc, $yy + 4.4, 'Desc.', 8.5, true);
            $p->textoDerecha($x1 - 2, $yy + 4.4, 'Valor', 8.5, true);

            return $yy + 6.5;
        };
        $y = $encabezado($y);
        foreach ($d['lineas'] as $l) {
            $descAncho = $cCant - 26 - $x0 - 2;
            $texto = $l['nombre'] . ($l['exento'] === '1' ? ' (exento)' : '') . ($l['descripcion'] !== '' ? "\n" . $l['descripcion'] : '');
            $lineas = $p->envolver($texto, $descAncho, 8.5);
            $alto = count($lineas) * 4.1 + 2;
            if ($y + $alto > $limite) {
                $p->nuevaPagina();
                $y = $encabezado(self::MARGEN + 4);
            }
            $ty = $y + 3.8;
            foreach ($lineas as $linea) {
                $p->texto($x0 + 2, $ty, $linea, 8.5);
                $ty += 4.1;
            }
            $cant = $l['cantidad'] !== '' ? $this->numero($l['cantidad']) . ($l['unidad'] !== '' ? ' ' . $l['unidad'] : '') : '';
            $p->textoDerecha($cCant, $y + 3.8, $cant, 8.5);
            $p->textoDerecha($cPrecio, $y + 3.8, $l['precio'] !== '' ? $this->numero($l['precio']) : '', 8.5);
            $p->textoDerecha($cDesc, $y + 3.8, $l['descuentoPct'] !== '' ? $this->numero($l['descuentoPct']) . '%' : '', 8.5);
            $p->textoDerecha($x1 - 2, $y + 3.8, $this->numero($l['monto']), 8.5);
            $y += $alto;
            $p->linea($x0, $y, $x1, $y, 0.1, [0.75, 0.75, 0.75]);
        }
        $p->linea($x0, $y, $x1, $y, 0.25);

        return $y + 4;
    }

    /**
     * @param array<string, mixed> $d
     * @param array<string, mixed> $opciones
     */
    private function pie(array $d, DocumentoEmitido $documento, float $y, array $opciones): void
    {
        $p = $this->pdf;
        $x1 = PdfEscritor::ANCHO_MM - self::MARGEN;
        $pieY = PdfEscritor::ALTO_MM - 76.0;
        if ($y > $pieY) {
            $p->nuevaPagina();
            $y = self::MARGEN;
        }
        $t = $d['totales'];
        $filas = [];
        if ($t['neto'] !== '') {
            $filas[] = ['Monto neto', $this->numero($t['neto'])];
        }
        if ($t['exento'] !== '') {
            $filas[] = ['Monto exento', $this->numero($t['exento'])];
        }
        if ($t['iva'] !== '') {
            $filas[] = ['IVA (' . ($t['tasaIva'] !== '' ? $this->numero($t['tasaIva']) : '19') . '%)', $this->numero($t['iva'])];
        }
        $anchoT = 62.0;
        $xt = $x1 - $anchoT;
        $ty = max($y, $pieY);
        foreach ($filas as [$k, $val]) {
            $p->texto($xt + 2, $ty + 4, $k, 9);
            $p->textoDerecha($x1 - 2, $ty + 4, '$ ' . $val, 9);
            $ty += 5.2;
        }
        $p->rellenar($xt, $ty, $anchoT, 7, self::GRIS);
        $p->rectangulo($xt, $ty, $anchoT, 7, 0.3);
        $p->texto($xt + 2, $ty + 4.9, 'TOTAL', 10, true);
        $p->textoDerecha($x1 - 2, $ty + 4.9, '$ ' . $this->numero($t['total']), 10, true);

        // Timbre PDF417
        // Se prueban varias cantidades de columnas y se elige la que da el módulo más grande dentro de 80 x 38 mm (lo más fácil de escanear).
        $enc = new Pdf417Encoder();
        $modulos = null;
        $escala = 0.0;
        for ($columnas = 4; $columnas <= 20; $columnas++) {
            try {
                $m = $enc->encode($documento->ted, $columnas);
            } catch (\InvalidArgumentException) {
                continue; // con tan pocas columnas el símbolo excede las 90 filas permitidas
            }
            $e = min(80.0 / count($m[0]), 38.0 / (count($m) * 3));
            if ($e > $escala) {
                [$modulos, $escala] = [$m, $e];
            }
        }
        $escala = max($escala, Pdf417Encoder::MIN_X_DIM_MM);
        $cols = count($modulos[0]);
        $filasN = count($modulos);
        $tx = self::MARGEN;
        $tyT = $pieY;
        $p->matriz($tx, $tyT, $modulos, $escala, $escala * 3);
        $altoT = $filasN * $escala * 3;
        $anchoTimbre = $cols * $escala;
        $res = isset($opciones['resolucionNumero']) ? 'Res. ' . $opciones['resolucionNumero'] . ' de ' . substr((string) ($opciones['resolucionFecha'] ?? $d['fecha']), 0, 4) . ' - ' : '';
        $p->textoCentrado($tx + $anchoTimbre / 2, $tyT + $altoT + 4, 'Timbre Electrónico SII', 8, true);
        $p->textoCentrado($tx + $anchoTimbre / 2, $tyT + $altoT + 7.8, $res . 'Verifique documento: www.sii.cl', 7.5);

        if (($opciones['cedible'] ?? false) && in_array($d['tipo'], [33, 34, 46], true)) {
            $p->textoDerecha($x1, PdfEscritor::ALTO_MM - 12, 'CEDIBLE', 10, true);
        }
    }

    private function rut(string $rut): string
    {
        $rut = Rut::toDteFormat($rut);
        [$num, $dv] = explode('-', $rut) + [1 => ''];

        return number_format((int) $num, 0, '', '.') . '-' . $dv;
    }

    private function fecha(string $iso): string
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $iso, $m)) {
            return $iso;
        }

        return (int) $m[3] . ' de ' . self::MESES[(int) $m[2] - 1] . ' de ' . $m[1];
    }

    /** Número con separador de miles «.» y decimal «,». */
    private function numero(string $n): string
    {
        if ($n === '') {
            return '';
        }
        $partes = explode('.', $n);
        $ent = number_format((int) $partes[0], 0, '', '.');

        return isset($partes[1]) && rtrim($partes[1], '0') !== '' ? $ent . ',' . rtrim($partes[1], '0') : $ent;
    }
}
