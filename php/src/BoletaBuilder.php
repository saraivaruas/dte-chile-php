<?php

declare(strict_types=1);

namespace IngeInnova\DteChile;

use DOMDocument;
use InvalidArgumentException;

/**
 * Arma el XML de una boleta electrónica (39) o boleta exenta (41) sin timbre ni firma; el resto del flujo es el mismo que el de {@see DteBuilder}:
 * TedSigner::sign() → DteBuilder::attachTed() → DteSigner::sign() → EnvioDteBuilder::buildBoleta() → SiiClient::uploadBoleta().
 *
 * Diferencias con una factura, según el esquema del SII:
 *  - Los precios de la boleta incluyen IVA: el neto y el IVA se descomponen desde el total (neto = total / 1,19). Con `preciosNetos` => true (IndMntNeto 2)
 *    los precios son netos y el IVA se suma.
 *  - El receptor es opcional: sin RUT se usa el genérico 66666666-6.
 *  - El emisor usa los nombres RznSocEmisor / GiroEmisor.
 *
 * Entrada: folio, fechaEmision, indicadorServicio? (1 servicios periódicos domiciliarios, 2 servicios periódicos, 3 ventas y servicios — por defecto 3, 4 espectáculos),
 *   emisor [rut, razonSocial, giro?, direccion?, comuna?, ciudad?], receptor? [rut?, razonSocial?, ...], items [nombre, cantidad?, precioUnitario? | monto?, exento?,
 *   descuentoPct?, ...], descuentosRecargos?, referencias?, preciosNetos?, rutProveedorSw?, periodoDesde?, periodoHasta?, fechaVencimiento?, medioPago?
 */
final class BoletaBuilder
{
    public const RUT_GENERICO = '66666666-6';

    private const ALIAS_EMISOR = ['rut' => 'RUTEmisor', 'razonSocial' => 'RznSocEmisor', 'giro' => 'GiroEmisor', 'direccion' => 'DirOrigen', 'comuna' => 'CmnaOrigen', 'ciudad' => 'CiudadOrigen', 'codigoSucursalSii' => 'CdgSIISucur'];
    private const ALIAS_RECEPTOR = ['rut' => 'RUTRecep', 'razonSocial' => 'RznSocRecep', 'direccion' => 'DirRecep', 'comuna' => 'CmnaRecep', 'ciudad' => 'CiudadRecep', 'correo' => 'CorreoRecep', 'contacto' => 'Contacto', 'telefono' => 'TelefonoRecep', 'codigoInterno' => 'CdgIntRecep'];
    private const ALIAS_ITEM = [
        'nombre' => 'NmbItem', 'descripcion' => 'DscItem', 'cantidad' => 'QtyItem', 'unidad' => 'UnmdItem', 'precioUnitario' => 'PrcItem', 'descuentoPct' => 'DescuentoPct',
        'descuentoMonto' => 'DescuentoMonto', 'recargoPct' => 'RecargoPct', 'recargoMonto' => 'RecargoMonto', 'exento' => 'IndExe', 'codigos' => 'CdgItem', 'monto' => 'MontoItem',
        'rutMandante' => 'RUTMandante',
    ];
    private const ALIAS_REFERENCIA = ['tipoDocumento' => 'TpoDocRef', 'folio' => 'FolioRef', 'codigo' => 'CodRef', 'razon' => 'RazonRef', 'codigoVendedor' => 'CodVndor', 'codigoCaja' => 'CodCaja'];
    private const ALIAS_DSCRCG = ['tipo' => 'TpoMov', 'valorTipo' => 'TpoValor', 'valor' => 'ValorDR', 'glosa' => 'GlosaDR', 'exento' => 'IndExeDR'];

    public function __construct(private readonly float $tasaIva = 19.0, private readonly bool $truncar = false)
    {
    }

    /** @param array<string, mixed> $data Ver la descripción de la clase. */
    public function build(int $tipo, array $data): DOMDocument
    {
        if (!DocumentType::isBoleta($tipo)) {
            throw new InvalidArgumentException("BoletaBuilder arma boletas (39 y 41); para el tipo {$tipo} use DteBuilder.");
        }
        foreach (['folio', 'fechaEmision', 'emisor', 'items'] as $campo) {
            if (!isset($data[$campo]) || $data[$campo] === '' || $data[$campo] === []) {
                throw new InvalidArgumentException("Falta el campo requerido \"{$campo}\".");
            }
        }
        if ((int) $data['folio'] < 1) {
            throw new InvalidArgumentException('El folio debe ser un número entero positivo.');
        }

        $emisor = $this->alias((array) $data['emisor'], self::ALIAS_EMISOR);
        if (!isset($emisor['RUTEmisor']) || !Rut::isValid((string) $emisor['RUTEmisor'])) {
            throw new InvalidArgumentException('El RUT del emisor falta o no es válido.');
        }
        $emisor['RUTEmisor'] = Rut::toDteFormat((string) $emisor['RUTEmisor']);

        $receptor = $this->alias((array) ($data['receptor'] ?? []), self::ALIAS_RECEPTOR);
        $receptor['RUTRecep'] = Rut::toDteFormat((string) ($receptor['RUTRecep'] ?? self::RUT_GENERICO));
        if (!Rut::isValid($receptor['RUTRecep'])) {
            throw new InvalidArgumentException('El RUT del receptor no es válido.');
        }

        $exenta = $tipo === DocumentType::BOLETA_NO_AFECTA_EXENTA;
        $netos = !empty($data['preciosNetos']);
        if ($exenta && $netos) {
            throw new InvalidArgumentException('La boleta exenta (41) no tiene IVA: no admite preciosNetos.');
        }
        $detalle = array_map(function (array $i) use ($exenta): array {
            $i = $this->alias($i, self::ALIAS_ITEM);
            if (array_key_exists('IndExe', $i)) {
                $i['IndExe'] = $i['IndExe'] === true ? 1 : ($i['IndExe'] === false ? null : $i['IndExe']);
            }
            if (isset($i['CdgItem'])) {
                $i['CdgItem'] = array_map(fn (array $c): array => $this->alias($c, ['tipo' => 'TpoCodigo', 'valor' => 'VlrCodigo']), array_values((array) $i['CdgItem']));
            }

            return $exenta && !isset($i['IndExe']) ? ['IndExe' => 1] + $i : $i;
        }, array_values((array) $data['items']));
        $dscRcg = array_map(fn (array $d): array => $this->alias($d, self::ALIAS_DSCRCG), array_values((array) ($data['descuentosRecargos'] ?? [])));

        $calculo = (new Totals($this->tasaIva, 0, $exenta))->calcular($detalle, $dscRcg, [], false);
        $t = $calculo['totales'];
        $afecto = (int) ($t['MntNeto'] ?? 0);
        $exento = (int) ($t['MntExe'] ?? 0);
        $totales = [];
        if ($afecto > 0) {
            // Con precios con IVA incluido el afecto es el total bruto: se descompone. Con precios netos el IVA se suma.
            $neto = $netos ? $afecto : (int) round($afecto / (1 + $this->tasaIva / 100));
            $iva = $netos ? (int) round($afecto * $this->tasaIva / 100) : $afecto - $neto;
            $totales = ['MntNeto' => $neto, 'IVA' => $iva];
            $total = $neto + $iva + $exento;
        } else {
            $total = $exento;
        }
        if ($exento > 0) {
            $totales['MntExe'] = $exento;
        }
        if (!isset($totales['MntNeto']) && !isset($totales['MntExe'])) {
            $totales['MntExe'] = 0;
        }
        $totales['MntTotal'] = $total;
        if (isset($t['MontoNF'])) {
            $totales['MontoNF'] = $t['MontoNF'];
        }
        foreach (['totalPeriodo' => 'TotalPeriodo', 'saldoAnterior' => 'SaldoAnterior', 'valorPagar' => 'VlrPagar'] as $alias => $sii) {
            if (isset($data[$alias])) {
                $totales[$sii] = $data[$alias];
            }
        }

        $idDoc = ['TipoDTE' => $tipo, 'Folio' => $data['folio'], 'FchEmis' => $data['fechaEmision'], 'IndServicio' => $data['indicadorServicio'] ?? 3];
        if ($netos) {
            $idDoc['IndMntNeto'] = 2;
        }
        foreach (['periodoDesde' => 'PeriodoDesde', 'periodoHasta' => 'PeriodoHasta', 'fechaVencimiento' => 'FchVenc', 'medioPago' => 'MedioPago'] as $alias => $sii) {
            if (isset($data[$alias])) {
                $idDoc[$sii] = $data[$alias];
            }
        }

        $encabezado = ['IdDoc' => $idDoc, 'Emisor' => $emisor, 'Receptor' => $receptor];
        if (isset($data['rutProveedorSw'])) {
            $encabezado['RUTProvSW'] = Rut::toDteFormat((string) $data['rutProveedorSw']);
        }
        $encabezado['Totales'] = $totales;

        $cuerpo = ['@ID' => "T{$tipo}F{$data['folio']}", 'Encabezado' => $encabezado, 'Detalle' => $calculo['detalle']];
        if ($calculo['dscRcg'] !== []) {
            $cuerpo['DscRcgGlobal'] = $calculo['dscRcg'];
        }
        if (!empty($data['referencias'])) {
            $refs = array_values((array) $data['referencias']);
            $cuerpo['Referencia'] = array_map(fn (array $r, int $i): array => ['NroLinRef' => $i + 1] + $this->alias($r, self::ALIAS_REFERENCIA), $refs, array_keys($refs));
        }

        $doc = new DOMDocument('1.0', 'ISO-8859-1');
        $doc->formatOutput = false;
        $xml = new SpecXml(DteBuilder::NAMESPACE_URI, ['TED', 'TmstFirma'], $this->truncar);
        $raiz = $xml->element($doc, Spec::load('boleta'), ['@version' => '1.0', 'Documento' => $cuerpo], 'DTE');
        $raiz->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance'); // mismo contexto de firma que dentro del sobre
        $doc->appendChild($raiz);

        return $doc;
    }

    /**
     * @param array<string, mixed> $in
     * @param array<string, string> $map
     * @return array<string, mixed>
     */
    private function alias(array $in, array $map): array
    {
        $out = [];
        foreach ($in as $k => $v) {
            if ($v !== null && $v !== '') {
                $out[$map[$k] ?? $k] = $v;
            }
        }

        return $out;
    }
}
