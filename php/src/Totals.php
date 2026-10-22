<?php

declare(strict_types=1);

namespace IngeInnova\DteChile;

use InvalidArgumentException;

/**
 * Cálculo de las líneas de detalle y de los totales de un DTE, con las reglas del «Formato Documentos Tributarios Electrónicos» del SII:
 *
 *  - MontoItem = cantidad × precio − descuento + recargo (redondeado al peso).
 *  - Ítems afectos (sin IndExe), exentos (IndExe 1) y no facturables (IndExe 2) se suman por separado.
 *  - Los descuentos/recargos globales ajustan el grupo al que apuntan (IndExeDR): vacío = afectos, 1 = exentos, 2 = no facturables.
 *  - IVA = Monto neto × tasa (19 %), redondeado. Los impuestos adicionales y retenciones usan la tasa que entregue quien llama (cambian por ley).
 *  - Monto total = neto + exento + IVA + impuestos adicionales − retenciones. Lo no facturable (MontoNF) va aparte y no suma al total.
 *
 * Para lo que esta clase no cubre (liquidaciones-factura, margen de comercialización, créditos especiales, otras monedas...) el documento admite
 * un `totales` explícito, que reemplaza al calculado.
 */
final class Totals
{
    /** Códigos del SII que son retenciones de IVA (restan del total) y no impuestos adicionales (suman). */
    public const RETENCIONES = ['15', '30', '31', '32', '33', '34', '36', '37', '38', '39', '41', '46', '47', '48', '49', '301', '321', '331', '341', '361', '371', '481'];

    /**
     * @param int $decimales 0 para pesos; 2 para documentos de exportación en moneda extranjera.
     * @param bool $todoExento true en factura exenta y en exportación: no hay IVA y todo el monto va a «Monto exento».
     */
    public function __construct(
        private readonly float $tasaIva = 19.0,
        private readonly int $decimales = 0,
        private readonly bool $todoExento = false,
    ) {
    }

    private function r(float $x): int|float
    {
        return $this->decimales === 0 ? (int) round($x) : round($x, $this->decimales);
    }

    /**
     * @param list<array<string, mixed>> $detalle Líneas con nombres del SII (NmbItem, QtyItem, PrcItem, IndExe, CodImpAdic, descuentos y recargos...).
     * @param list<array<string, mixed>> $dscRcg Descuentos y recargos globales (TpoMov, TpoValor, ValorDR, IndExeDR, GlosaDR).
     * @param array<string, float|int> $tasasImpuestos Tasa (%) de cada código de impuesto adicional usado en el detalle.
     * @param bool $conIva false para documentos sin IVA (exentos, exportación).
     * @return array{detalle: list<array<string, mixed>>, dscRcg: list<array<string, mixed>>, totales: array<string, mixed>}
     */
    public function calcular(array $detalle, array $dscRcg, array $tasasImpuestos = [], bool $conIva = true): array
    {
        $grupos = ['afecto' => 0, 'exento' => 0, 'nf' => 0];
        $baseImpuesto = []; // código → suma de ítems afectos con ese código
        $lineas = [];

        foreach (array_values($detalle) as $i => $linea) {
            $n = $i + 1;
            $linea = ['NroLinDet' => $n] + $linea;
            [$linea, $monto] = $this->linea($linea, $n);
            $lineas[] = $linea;

            $ind = isset($linea['IndExe']) ? (string) $linea['IndExe'] : '';
            $grupo = match ($ind) {
                '' => 'afecto',
                '1' => 'exento',
                '2', '6' => 'nf',
                default => throw new InvalidArgumentException("Línea {$n}: el indicador de exención {$ind} no se calcula automáticamente; entregue el bloque «totales» completo."),
            };
            $grupos[$grupo] += $monto;
            if ($grupo === 'afecto') {
                foreach ((array) ($linea['CodImpAdic'] ?? []) as $codigo) {
                    $baseImpuesto[(string) $codigo] = ($baseImpuesto[(string) $codigo] ?? 0) + $monto;
                }
            }
        }

        $mixto = count($baseImpuesto) > 1 || (count($baseImpuesto) === 1 && array_sum($baseImpuesto) !== $grupos['afecto']);
        $netoAntes = $grupos['afecto'];
        $globales = [];
        foreach (array_values($dscRcg) as $i => $g) {
            $g = ['NroLinDR' => $i + 1] + $g;
            $ind = isset($g['IndExeDR']) ? (string) $g['IndExeDR'] : '';
            $clave = match ($ind) {
                '' => 'afecto',
                '1' => 'exento',
                '2' => 'nf',
                default => throw new InvalidArgumentException("Descuento/recargo {$g['NroLinDR']}: IndExeDR debe ser vacío, 1 o 2."),
            };
            $valor = (float) $g['ValorDR'];
            if (($g['TpoValor'] ?? '') === '$' && $mixto && $clave === 'afecto') {
                throw new InvalidArgumentException('Con ítems de distintos impuestos adicionales, el descuento o recargo global debe ser un porcentaje (TpoValor "%"), según el formato del SII.');
            }
            $monto = ($g['TpoValor'] ?? '') === '%' ? $this->r($grupos[$clave] * $valor / 100) : $this->r($valor);
            $grupos[$clave] += ($g['TpoMov'] === 'D' ? -1 : 1) * $monto;
            $globales[] = $g;
        }

        if ($this->todoExento) { // factura exenta y exportación: lo afecto y lo exento se informan juntos como «Monto exento»
            $grupos['exento'] += $grupos['afecto'];
            $grupos['afecto'] = 0;
            $conIva = false;
        }
        $neto = $grupos['afecto'];
        $exento = $grupos['exento'];
        $totales = [];
        if ($neto !== 0 || $exento === 0) {
            $totales['MntNeto'] = $neto;
        }
        if ($exento !== 0) {
            $totales['MntExe'] = $exento;
        }

        $iva = 0;
        if ($conIva && $neto > 0) {
            $iva = $this->r($neto * $this->tasaIva / 100);
            $totales['TasaIVA'] = $this->tasaIva;
            $totales['IVA'] = $iva;
        }

        $suma = 0;
        $reten = [];
        $razon = $netoAntes > 0 ? $neto / $netoAntes : 1.0; // el descuento global también reduce la base de cada impuesto
        foreach ($baseImpuesto as $codigoClave => $base) {
            $codigo = (string) $codigoClave; // PHP convierte las claves numéricas («15») en enteros: se vuelve a texto para comparar con los códigos del SII
            if (!array_key_exists($codigo, $tasasImpuestos)) {
                throw new InvalidArgumentException("El impuesto adicional o retención código {$codigo} necesita su tasa: indíquela en «impuestos» (por ejemplo ['{$codigo}' => 15]).");
            }
            $tasa = (float) $tasasImpuestos[$codigo];
            $montoImp = $this->r($base * $razon * $tasa / 100);
            $reten[] = ['TipoImp' => $codigo, 'TasaImp' => $tasa, 'MontoImp' => $montoImp];
            $suma += (in_array($codigo, self::RETENCIONES, true) ? -1 : 1) * $montoImp;
        }
        if ($reten !== []) {
            $totales['ImptoReten'] = $reten;
        }
        if ($grupos['nf'] !== 0) {
            $totales['MontoNF'] = $grupos['nf'];
        }
        $totales['MntTotal'] = $neto + $exento + $iva + $suma;

        return ['detalle' => $lineas, 'dscRcg' => $globales, 'totales' => $totales];
    }

    /**
     * @param array<string, mixed> $linea
     * @return array{0: array<string, mixed>, 1: int}
     */
    private function linea(array $linea, int $n): array
    {
        $precio = $linea['PrcItem'] ?? null;
        if ($precio !== null && $precio !== '') {
            $base = (float) ($linea['QtyItem'] ?? 1) * (float) $precio;
        } elseif (isset($linea['MontoItem'])) {
            $base = (float) $linea['MontoItem'];
        } else {
            throw new InvalidArgumentException("Línea {$n} («" . ($linea['NmbItem'] ?? '?') . '»): indique precioUnitario (con cantidad) o el monto de la línea.');
        }

        $desc = isset($linea['DescuentoMonto']) ? $this->r((float) $linea['DescuentoMonto']) : (isset($linea['DescuentoPct']) ? $this->r($base * (float) $linea['DescuentoPct'] / 100) : 0);
        $rec = isset($linea['RecargoMonto']) ? $this->r((float) $linea['RecargoMonto']) : (isset($linea['RecargoPct']) ? $this->r($base * (float) $linea['RecargoPct'] / 100) : 0);
        if (isset($linea['DescuentoPct']) && !isset($linea['DescuentoMonto'])) {
            $linea['DescuentoMonto'] = $desc;
        }
        if (isset($linea['RecargoPct']) && !isset($linea['RecargoMonto'])) {
            $linea['RecargoMonto'] = $rec;
        }

        $monto = $this->r($base - $desc + $rec);
        if ($monto < 0 && !isset($linea['IndExe'])) {
            throw new InvalidArgumentException("Línea {$n}: el monto no puede ser negativo.");
        }
        $linea['MontoItem'] = $monto;

        return [$linea, $monto];
    }
}
