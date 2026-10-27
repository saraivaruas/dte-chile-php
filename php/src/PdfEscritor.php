<?php

declare(strict_types=1);

namespace IngeInnova\DteChile;

/**
 * Escritor de PDF mínimo y sin dependencias (tamaño Carta, fuentes estándar Helvetica, sin imágenes): lo justo para la representación impresa.
 * Las coordenadas son milímetros con el origen arriba a la izquierda. El texto se recibe en UTF-8 y se escribe en WinAnsi (compatible con ISO-8859-1).
 */
final class PdfEscritor
{
    public const ANCHO_MM = 215.9;
    public const ALTO_MM = 279.4;
    private const PT = 72 / 25.4;

    /** Anchos Helvetica (milésimas de em) para ASCII 32..126. */
    private const ANCHOS = [
        278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278, 556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 278, 278, 584, 584, 584, 556,
        1015, 667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778, 667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 278, 278, 278, 469, 556,
        333, 556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556, 556, 556, 333, 500, 278, 556, 500, 722, 500, 500, 500, 334, 260, 334, 584,
    ];

    /** @var string[] Contenido de cada página. */
    private array $paginas = [];
    /** @var array<int, array{datos: string, ancho: int, alto: int, espacio: string}> Imágenes JPEG incluidas. */
    private array $imagenes = [];
    private int $actual = -1;

    public function nuevaPagina(): void
    {
        $this->paginas[] = '';
        $this->actual = count($this->paginas) - 1;
    }

    /**
     * Registra una imagen JPEG y devuelve su número. Solo JPEG (el PDF lo incrusta tal cual, sin decodificarlo).
     *
     * @return array{0: int, 1: int, 2: int} [número de imagen, ancho px, alto px]
     */
    public function agregarJpeg(string $bytes): array
    {
        if (!str_starts_with($bytes, "\xFF\xD8")) {
            throw new \InvalidArgumentException('El logotipo debe ser una imagen JPEG.');
        }
        $i = 2;
        $n = strlen($bytes);
        while ($i + 9 < $n) {
            if ($bytes[$i] !== "\xFF") {
                $i++;
                continue;
            }
            $marca = ord($bytes[$i + 1]);
            if ($marca === 0xFF) {
                $i++;
                continue;
            }
            if (in_array($marca, [0xD8, 0x01], true) || ($marca >= 0xD0 && $marca <= 0xD7)) {
                $i += 2;
                continue;
            }
            $largo = (ord($bytes[$i + 2]) << 8) | ord($bytes[$i + 3]);
            if ($marca >= 0xC0 && $marca <= 0xCF && !in_array($marca, [0xC4, 0xC8, 0xCC], true)) {
                $alto = (ord($bytes[$i + 5]) << 8) | ord($bytes[$i + 6]);
                $ancho = (ord($bytes[$i + 7]) << 8) | ord($bytes[$i + 8]);
                $comp = ord($bytes[$i + 9]);
                $espacio = [1 => 'DeviceGray', 3 => 'DeviceRGB'][$comp] ?? throw new \InvalidArgumentException('El JPEG del logotipo debe ser en escala de grises o RGB (no CMYK).');
                if ($ancho < 1 || $alto < 1) {
                    break;
                }
                $this->imagenes[] = ['datos' => $bytes, 'ancho' => $ancho, 'alto' => $alto, 'espacio' => $espacio];

                return [count($this->imagenes), $ancho, $alto];
            }
            $i += 2 + $largo;
        }

        throw new \InvalidArgumentException('No se pudo leer el tamaño del JPEG del logotipo (archivo dañado).');
    }

    /** Dibuja una imagen registrada con {@see agregarJpeg()} dentro del rectángulo dado (sin deformarla; centrada). */
    public function imagen(int $numero, int $anchoPx, int $altoPx, float $x, float $yMm, float $anchoMax, float $altoMax): void
    {
        $razon = min($anchoMax / $anchoPx, $altoMax / $altoPx);
        $w = $anchoPx * $razon;
        $h = $altoPx * $razon;
        $this->emitir(sprintf('q %s 0 0 %s %s %s cm /Im%d Do Q', $this->mm($w), $this->mm($h), $this->mm($x + ($anchoMax - $w) / 2), $this->y($yMm + ($altoMax + $h) / 2), $numero));
    }

    public function numeroPaginas(): int
    {
        return count($this->paginas);
    }

    private function mm(float $v): string
    {
        return rtrim(rtrim(sprintf('%.3F', $v * self::PT), '0'), '.');
    }

    private function y(float $mm): string
    {
        return rtrim(rtrim(sprintf('%.3F', (self::ALTO_MM - $mm) * self::PT), '0'), '.');
    }

    private function emitir(string $s): void
    {
        $this->paginas[$this->actual] .= $s . "\n";
    }

    /** Convierte UTF-8 a los bytes que se escriben en el PDF (Latin-1; lo que no existe en Latin-1 pasa a «?»). */
    public static function codificar(string $utf8): string
    {
        $anterior = mb_substitute_character();
        mb_substitute_character(63);
        $l1 = mb_convert_encoding($utf8, 'ISO-8859-1', 'UTF-8');
        mb_substitute_character($anterior);

        return $l1;
    }

    /** Ancho del texto en milímetros. */
    public function ancho(string $utf8, float $puntos, bool $negrita = false): float
    {
        $total = 0;
        foreach (mb_str_split($utf8) as $c) {
            $total += $this->anchoCaracter($c);
        }

        return $total * $puntos / 1000 * ($negrita ? 1.07 : 1) / self::PT;
    }

    private function anchoCaracter(string $c): int
    {
        $o = mb_ord($c);
        if ($o >= 32 && $o <= 126) {
            return self::ANCHOS[$o - 32];
        }
        // Letras con tilde: el ancho de la letra base (la «í» es algo más ancha que la «i»).
        $base = ['á' => 'a', 'à' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ó' => 'o', 'ö' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
            'Á' => 'A', 'É' => 'E', 'Ó' => 'O', 'Ú' => 'U', 'Ñ' => 'N', 'Í' => 'I', 'í' => 'i°', 'º' => 'o', '°' => 'o'];
        $b = $base[$c] ?? null;
        if ($b === 'i°') {
            return 278;
        }
        if ($b !== null) {
            return self::ANCHOS[mb_ord($b) - 32];
        }

        return 556;
    }

    private function cadena(string $utf8): string
    {
        $l1 = self::codificar($utf8);

        return '(' . strtr($l1, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)', "\r" => '\\r', "\n" => '\\n']) . ')';
    }

    /** @param array{0: float, 1: float, 2: float}|null $color RGB 0..1 */
    public function texto(float $x, float $yMm, string $texto, float $puntos = 9, bool $negrita = false, ?array $color = null): void
    {
        $c = $color !== null ? sprintf('%.3F %.3F %.3F rg', ...$color) : '0 g';
        $this->emitir(sprintf('BT %s /%s %s Tf %s %s Td %s Tj ET', $c, $negrita ? 'F2' : 'F1', $puntos, $this->mm($x), $this->y($yMm), $this->cadena($texto)));
    }

    public function textoDerecha(float $xDerecha, float $yMm, string $texto, float $puntos = 9, bool $negrita = false, ?array $color = null): void
    {
        $this->texto($xDerecha - $this->ancho($texto, $puntos, $negrita), $yMm, $texto, $puntos, $negrita, $color);
    }

    public function textoCentrado(float $xCentro, float $yMm, string $texto, float $puntos = 9, bool $negrita = false, ?array $color = null): void
    {
        $this->texto($xCentro - $this->ancho($texto, $puntos, $negrita) / 2, $yMm, $texto, $puntos, $negrita, $color);
    }

    /** Parte un texto en líneas que caben en $anchoMm. @return string[] */
    public function envolver(string $texto, float $anchoMm, float $puntos = 9, bool $negrita = false): array
    {
        $lineas = [];
        foreach (preg_split('/\R/u', $texto) ?: [''] as $parrafo) {
            $actual = '';
            foreach (preg_split('/\s+/u', trim($parrafo)) ?: [] as $palabra) {
                $prueba = $actual === '' ? $palabra : $actual . ' ' . $palabra;
                if ($actual !== '' && $this->ancho($prueba, $puntos, $negrita) > $anchoMm) {
                    $lineas[] = $actual;
                    $actual = $palabra;
                } else {
                    $actual = $prueba;
                }
                // Palabra sola más ancha que la columna: se corta por caracteres.
                while ($this->ancho($actual, $puntos, $negrita) > $anchoMm && mb_strlen($actual) > 1) {
                    $n = mb_strlen($actual);
                    while ($n > 1 && $this->ancho(mb_substr($actual, 0, $n), $puntos, $negrita) > $anchoMm) {
                        $n--;
                    }
                    $lineas[] = mb_substr($actual, 0, $n);
                    $actual = mb_substr($actual, $n);
                }
            }
            $lineas[] = $actual;
        }

        return $lineas;
    }

    public function linea(float $x1, float $y1, float $x2, float $y2, float $grosorMm = 0.2, ?array $color = null): void
    {
        $c = $color !== null ? sprintf('%.3F %.3F %.3F RG', ...$color) : '0 G';
        $this->emitir(sprintf('%s %s w %s %s m %s %s l S', $c, $this->mm($grosorMm), $this->mm($x1), $this->y($y1), $this->mm($x2), $this->y($y2)));
    }

    public function rectangulo(float $x, float $yMm, float $ancho, float $alto, float $grosorMm = 0.3, ?array $color = null): void
    {
        $c = $color !== null ? sprintf('%.3F %.3F %.3F RG', ...$color) : '0 G';
        $this->emitir(sprintf('%s %s w %s %s %s %s re S', $c, $this->mm($grosorMm), $this->mm($x), $this->y($yMm + $alto), $this->mm($ancho), $this->mm($alto)));
    }

    public function rellenar(float $x, float $yMm, float $ancho, float $alto, array $color): void
    {
        $this->emitir(sprintf('%.3F %.3F %.3F rg %s %s %s %s re f', $color[0], $color[1], $color[2], $this->mm($x), $this->y($yMm + $alto), $this->mm($ancho), $this->mm($alto)));
    }

    /**
     * Dibuja una matriz de módulos (PDF417) como rectángulos negros, uniendo los módulos contiguos de cada fila.
     *
     * @param bool[][] $modulos
     */
    public function matriz(float $x, float $yMm, array $modulos, float $moduloAncho, float $moduloAlto): void
    {
        $partes = ['0 g'];
        foreach ($modulos as $fila => $columnas) {
            $inicio = null;
            $n = count($columnas);
            for ($c = 0; $c <= $n; $c++) {
                $negro = $c < $n && $columnas[$c];
                if ($negro && $inicio === null) {
                    $inicio = $c;
                } elseif (!$negro && $inicio !== null) {
                    $partes[] = sprintf('%s %s %s %s re', $this->mm($x + $inicio * $moduloAncho), $this->y($yMm + ($fila + 1) * $moduloAlto), $this->mm(($c - $inicio) * $moduloAncho), $this->mm($moduloAlto));
                    $inicio = null;
                }
            }
        }
        $partes[] = 'f';
        $this->emitir(implode("\n", $partes));
    }

    /** El archivo PDF completo. */
    public function generar(string $titulo = ''): string
    {
        if ($this->paginas === []) {
            $this->nuevaPagina();
        }
        $objetos = [];
        $n = count($this->paginas);
        $objetos[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $kids = implode(' ', array_map(static fn (int $i): string => (5 + $i * 2) . ' 0 R', range(0, $n - 1)));
        $objetos[2] = "<< /Type /Pages /Kids [{$kids}] /Count {$n} >>";
        $objetos[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objetos[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $w = sprintf('%.2F', self::ANCHO_MM * self::PT);
        $h = sprintf('%.2F', self::ALTO_MM * self::PT);
        $idInfo = 5 + $n * 2;
        $xobj = '';
        if ($this->imagenes !== []) {
            $xobj = ' /XObject << ' . implode(' ', array_map(fn (int $k): string => '/Im' . ($k + 1) . ' ' . ($idInfo + 1 + $k) . ' 0 R', array_keys($this->imagenes))) . ' >>';
        }
        foreach ($this->paginas as $i => $contenido) {
            $idPagina = 5 + $i * 2;
            $objetos[$idPagina] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 {$w} {$h}] /Resources << /Font << /F1 3 0 R /F2 4 0 R >>{$xobj} >> /Contents " . ($idPagina + 1) . ' 0 R >>';
            $objetos[$idPagina + 1] = '<< /Length ' . strlen($contenido) . " >>\nstream\n" . $contenido . "endstream";
        }
        $objetos[$idInfo] = '<< /Title ' . $this->cadena($titulo) . ' /Producer (dte-chile) >>';
        foreach ($this->imagenes as $k => $im) {
            $objetos[$idInfo + 1 + $k] = "<< /Type /XObject /Subtype /Image /Width {$im['ancho']} /Height {$im['alto']} /ColorSpace /{$im['espacio']} /BitsPerComponent 8 /Filter /DCTDecode /Length " . strlen($im['datos']) . " >>\nstream\n" . $im['datos'] . "\nendstream";
        }

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objetos as $id => $cuerpo) {
            $offsets[$id] = strlen($pdf);
            $pdf .= "{$id} 0 obj\n{$cuerpo}\nendobj\n";
        }
        $xref = strlen($pdf);
        $total = count($objetos) + 1;
        $pdf .= "xref\n0 {$total}\n0000000000 65535 f \n";
        foreach ($offsets as $o) {
            $pdf .= sprintf("%010d 00000 n \n", $o);
        }
        $pdf .= "trailer\n<< /Size {$total} /Root 1 0 R /Info {$idInfo} 0 R >>\nstartxref\n{$xref}\n%%EOF\n";

        return $pdf;
    }
}
