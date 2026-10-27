<?php

declare(strict_types=1);

namespace IngeInnova\DteChile\Tests;

use IngeInnova\DteChile\BoletaBuilder;
use IngeInnova\DteChile\DteBuilder;
use IngeInnova\DteChile\Pdf417Encoder;
use IngeInnova\DteChile\TedSigner;
use ReflectionClass;

/**
 * Decodificador PDF417 independiente del encoder (solo para pruebas): reconstruye los bytes originales a partir de
 * la matriz de módulos. Reimplementa, a partir de la especificación, el mismo criterio que usa el decoder de
 * referencia de ZXing (DecodedBitStreamParser.byteCompaction) para distinguir un grupo de 5 codewords (6 bytes)
 * de una cola literal de 1-5 bytes: solo es un grupo de 6 bytes si, tras consumirlo, sigue habiendo más datos (o el
 * modo era 924), si no, se reinterpreta como bytes literales.
 */
function pdf417Decode(array $matrix, int $columns): string
{
    static $reverse = null;
    static $startPattern;
    static $stopPattern;
    if ($reverse === null) {
        $ref = new ReflectionClass(Pdf417Encoder::class);
        $reverse = [];
        foreach ($ref->getConstant('CODEWORD_TABLE') as $cluster => $words) {
            foreach ($words as $word => $pattern) {
                $reverse[$pattern] = [$cluster, $word];
            }
        }
        $startPattern = $ref->getConstant('START_PATTERN');
        $stopPattern = $ref->getConstant('STOP_PATTERN');
    }

    $leer = static function (array $bits, int &$pos, int $len) use (&$leer): int {
        $value = 0;
        for ($i = 0; $i < $len; $i++) {
            $value = ($value << 1) | ($bits[$pos++] ? 1 : 0);
        }

        return $value;
    };

    $codewords = [];
    foreach ($matrix as $fila => $bits) {
        $pos = 0;
        afirma($leer($bits, $pos, 17) === $startPattern, "fila $fila: patrón de inicio inválido");
        afirma(isset($reverse[$leer($bits, $pos, 17)]), "fila $fila: indicador izquierdo no reconocido");
        for ($c = 0; $c < $columns; $c++) {
            $patron = $leer($bits, $pos, 17);
            afirma(isset($reverse[$patron]), "fila $fila columna $c: codeword no reconocido en la tabla");
            $codewords[] = $reverse[$patron][1];
        }
        afirma(isset($reverse[$leer($bits, $pos, 17)]), "fila $fila: indicador derecho no reconocido");
        afirma($leer($bits, $pos, 18) === $stopPattern, "fila $fila: patrón de fin inválido (¿truncated?)");
        igual(count($bits), $pos, "fila $fila: bits sobrantes tras decodificar");
    }

    $lengthDescriptor = $codewords[0];
    $extendido = array_slice($codewords, 0, $lengthDescriptor); // sin los codewords de corrección de errores
    $modo = $extendido[1]; // primer codeword tras el length descriptor
    $datosYRelleno = array_slice($extendido, 2);

    // El relleno (codeword 900, igual al latch de modo texto) termina naturalmente la decodificación de bytes.
    $corte = count($datosYRelleno);
    foreach ($datosYRelleno as $i => $w) {
        if ($w >= 900) {
            $corte = $i;
            break;
        }
    }
    $palabras = array_slice($datosYRelleno, 0, $corte);

    $bytes = [];
    $i = 0;
    $total = count($palabras);
    while ($i < $total) {
        $valor = 0;
        $cuenta = 0;
        do {
            $valor = 900 * $valor + $palabras[$i++];
            $cuenta++;
        } while ($cuenta < 5 && $i < $total);

        if ($cuenta === 5 && ($modo === 924 || $i < $total)) {
            for ($j = 0; $j < 6; $j++) {
                $bytes[] = ($valor >> (8 * (5 - $j))) & 0xFF;
            }
        } else {
            $i -= $cuenta;
            for ($k = 0; $k < $cuenta; $k++) {
                $bytes[] = $palabras[$i++];
            }
        }
    }

    return implode('', array_map('chr', $bytes));
}

function afirmaSimboloValido(array $matrix): void
{
    $filas = count($matrix);
    afirma($filas >= 3 && $filas <= 90, "El símbolo tiene $filas filas, fuera del rango permitido por PDF417 (3-90).");
    foreach ($matrix as $bits) {
        afirma(count($bits) === count($matrix[0]), 'Todas las filas deberían tener el mismo largo (mismo número de columnas).');
    }
}

// ================================================================= pruebas: Pdf417Encoder
prueba('PDF417: el timbre de una factura normal se decodifica byte a byte igual al original', function (): void {
    $caf = Fixtures::caf(33, 1, 100);
    $datos = Fixtures::datosFactura(7);
    $b = new DteBuilder();
    $doc = $b->build(33, $datos);
    $tedDoc = (new TedSigner())->sign($caf['caf'], 33, $datos + ['montoTotal' => $b->montoTotal($doc)]);
    $tedBytes = Pdf417Encoder::tedToBytes($tedDoc);

    $encoder = new Pdf417Encoder();
    $matrix = $encoder->encode($tedBytes);
    afirmaSimboloValido($matrix);

    $columnas = (count($matrix[0]) - 17 - 17 - 17 - 18) / 17;
    igual($tedBytes, pdf417Decode($matrix, (int) $columnas), 'bytes decodificados del timbre PDF417');
});

prueba('PDF417: se decodifica igual con tildes, ñ y & en el receptor y el detalle', function (): void {
    $caf = Fixtures::caf(33, 1, 100);
    $datos = Fixtures::datosFactura(9, [
        'receptor' => ['rut' => '77111222-6', 'razonSocial' => 'Peña & Cía Ñandú'],
        'items' => [['nombre' => 'Diseño año – café, piñón & limón', 'cantidad' => 2, 'precioUnitario' => 1500]],
    ]);
    $b = new DteBuilder();
    $doc = $b->build(33, $datos);
    $tedDoc = (new TedSigner())->sign($caf['caf'], 33, $datos + ['montoTotal' => $b->montoTotal($doc)]);
    $tedBytes = Pdf417Encoder::tedToBytes($tedDoc);
    afirma(str_contains($tedBytes, "\xD1"), 'El TED de prueba debería contener bytes ISO-8859-1 de la Ñ.');

    $encoder = new Pdf417Encoder();
    $matrix = $encoder->encode($tedBytes);
    afirmaSimboloValido($matrix);

    $columnas = (count($matrix[0]) - 17 - 17 - 17 - 18) / 17;
    igual($tedBytes, pdf417Decode($matrix, (int) $columnas), 'bytes decodificados del timbre PDF417 con tildes/ñ/&');
});

prueba('PDF417: el timbre de una boleta también se decodifica igual al original', function (): void {
    $caf = Fixtures::caf(39, 1, 100);
    $datos = datosBoleta(3);
    $b = new BoletaBuilder();
    $doc = $b->build(39, $datos);
    $m = (new DteBuilder())->montoTotal($doc);
    $tedDoc = (new TedSigner())->sign($caf['caf'], 39, $datos + ['montoTotal' => $m]);
    $tedBytes = Pdf417Encoder::tedToBytes($tedDoc);

    $encoder = new Pdf417Encoder();
    $matrix = $encoder->encode($tedBytes);
    afirmaSimboloValido($matrix);

    $columnas = (count($matrix[0]) - 17 - 17 - 17 - 18) / 17;
    igual($tedBytes, pdf417Decode($matrix, (int) $columnas), 'bytes decodificados del timbre PDF417 de una boleta');
});

prueba('PDF417: rechaza datos vacíos', function (): void {
    lanza(fn () => (new Pdf417Encoder())->encode(''), 'vacío');
});

prueba('PDF417: toSvg() produce un SVG válido con la quiet zone y la razón 3:1 mínimas del Instructivo', function (): void {
    $encoder = new Pdf417Encoder();
    $matrix = $encoder->encode('<TED version="1.0"><DD><RE>11111111-1</RE></DD></TED>');
    $svg = $encoder->toSvg($matrix);

    afirma(str_starts_with($svg, '<svg'), 'El SVG debería empezar con <svg.');
    afirma(str_ends_with($svg, '</svg>'), 'El SVG debería cerrar con </svg>.');
    afirma(substr_count($svg, '<rect') > count($matrix), 'El SVG debería tener al menos un <rect> por fila además del fondo.');

    lanza(fn () => $encoder->toSvg($matrix, 0.1), 'X Dim');
});

prueba('PDF417: columnas explícitas fuera de 1-30 se rechazan', function (): void {
    $encoder = new Pdf417Encoder();
    lanza(fn () => $encoder->encode('datos de prueba', 0), 'columnas');
    lanza(fn () => $encoder->encode('datos de prueba', 31), 'columnas');
});
