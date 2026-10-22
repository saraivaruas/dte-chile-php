<?php

declare(strict_types=1);

namespace IngeInnova\DteChile;

use DateTimeImmutable;
use DateTimeZone;
use DOMDocument;
use InvalidArgumentException;
use RuntimeException;

/**
 * Fase 1 del flujo de un DTE: arma el XML del documento (encabezado, detalle, descuentos, referencias, totales) SIN timbre (TED) ni firma; eso lo agregan
 * {@see TedSigner} y {@see DteSigner} sobre el mismo DOMDocument.
 *
 * El XML se genera desde la especificación oficial del SII ({@see SpecXml}), así que el orden y los campos obligatorios los dicta el esquema, no el código.
 * Tipos que acepta el esquema DTE: 33, 34, 46, 52, 56, 61 (documentos), 43 (liquidación-factura) y 110, 111, 112 (exportación).
 * Las boletas (39 y 41) tienen su propio esquema y se arman con {@see BoletaBuilder}.
 *
 * Cada bloque admite los nombres «amigables» de abajo o, directamente, los nombres de elemento del SII (por ejemplo `RUTEmisor`, `GiroEmis`, `CodImpAdic`).
 *
 * Entrada (resumen):
 *   folio, fechaEmision, formaPago?, fechaVencimiento?, indicadorServicio?, indicadorTraslado?, tipoDespacho?, periodoDesde?, periodoHasta?, medioPago?,
 *   idDoc?  (campos de IdDoc con nombre SII)
 *   emisor:   rut, razonSocial, giro, actividadEconomica (código o lista de hasta 4), direccion?, comuna?, ciudad?, telefono?, correo?, sucursal?, ...
 *   receptor: rut, razonSocial, giro?, direccion?, comuna?, ciudad?, correo?, contacto?, ...
 *   items:    [nombre, cantidad?, precioUnitario? | monto?, descripcion?, unidad?, exento? (true|1|2), descuentoPct?, descuentoMonto?, recargoPct?, recargoMonto?,
 *              codigos? [[tipo, valor]], impuestoAdicional? (código o lista)]
 *   impuestos?: [código => tasa %] para los impuestos adicionales y retenciones usados en los ítems
 *   descuentosRecargos?: [[tipo 'D'|'R', valorTipo '%'|'$', valor, glosa?, exento? (1|2)]]
 *   referencias?: [[tipoDocumento, folio, fecha, codigo? (1 anula, 2 corrige texto, 3 corrige montos), razon?, global?, rutOtro?]]
 *   transporte?: [patente?, rutTransportista?, chofer? [rut, nombre], direccionDestino?, comunaDestino?, ciudadDestino?, aduana? (nombres SII)]
 *   totales?: bloque Totales completo con nombres SII; reemplaza al calculado (casos que el cálculo automático no cubre).
 *   otraMoneda?, subtotales?, comisiones?, geoReferencia?, manejoMadera?: bloques con nombres SII, pasan tal cual.
 */
final class DteBuilder
{
    public const NAMESPACE_URI = 'http://www.sii.cl/SiiDte';
    private const XSI = 'http://www.w3.org/2001/XMLSchema-instance';

    private const ALIAS_ID_DOC = [
        'formaPago' => 'FmaPago', 'fechaVencimiento' => 'FchVenc', 'indicadorServicio' => 'IndServicio', 'indicadorTraslado' => 'IndTraslado',
        'tipoDespacho' => 'TipoDespacho', 'periodoDesde' => 'PeriodoDesde', 'periodoHasta' => 'PeriodoHasta', 'medioPago' => 'MedioPago',
        'indicadorNoRebaja' => 'IndNoRebaja', 'fechaCancelacion' => 'FchCancel', 'montoCancelado' => 'MntCancel', 'saldoInsoluto' => 'SaldoInsol',
        'tipoCuentaPago' => 'TpoCtaPago', 'numeroCuentaPago' => 'NumCtaPago', 'bancoPago' => 'BcoPago',
    ];
    private const ALIAS_EMISOR = [
        'rut' => 'RUTEmisor', 'razonSocial' => 'RznSoc', 'giro' => 'GiroEmis', 'telefono' => 'Telefono', 'correo' => 'CorreoEmisor',
        'actividadEconomica' => 'Acteco', 'direccion' => 'DirOrigen', 'comuna' => 'CmnaOrigen', 'ciudad' => 'CiudadOrigen', 'sucursal' => 'Sucursal',
        'codigoSucursalSii' => 'CdgSIISucur', 'codigoVendedor' => 'CdgVendedor', 'guiaExportacion' => 'GuiaExport',
    ];
    private const ALIAS_RECEPTOR = [
        'rut' => 'RUTRecep', 'razonSocial' => 'RznSocRecep', 'giro' => 'GiroRecep', 'direccion' => 'DirRecep', 'comuna' => 'CmnaRecep', 'ciudad' => 'CiudadRecep',
        'correo' => 'CorreoRecep', 'contacto' => 'Contacto', 'codigoInterno' => 'CdgIntRecep', 'extranjero' => 'Extranjero',
        'direccionPostal' => 'DirPostal', 'comunaPostal' => 'CmnaPostal', 'ciudadPostal' => 'CiudadPostal',
    ];
    private const ALIAS_ITEM = [
        'nombre' => 'NmbItem', 'descripcion' => 'DscItem', 'cantidad' => 'QtyItem', 'unidad' => 'UnmdItem', 'precioUnitario' => 'PrcItem',
        'descuentoPct' => 'DescuentoPct', 'descuentoMonto' => 'DescuentoMonto', 'recargoPct' => 'RecargoPct', 'recargoMonto' => 'RecargoMonto',
        'exento' => 'IndExe', 'tipoDocumentoLiquidado' => 'TpoDocLiq', 'codigos' => 'CdgItem', 'impuestoAdicional' => 'CodImpAdic', 'monto' => 'MontoItem', 'retenedor' => 'Retenedor',
        'fechaElaboracion' => 'FchElabor', 'fechaVencimiento' => 'FchVencim', 'otraMoneda' => 'OtrMnda',
    ];
    private const ALIAS_TRANSPORTE = [
        'patente' => 'Patente', 'patenteCarro' => 'PatenteCarro', 'rutTransportista' => 'RUTTrans', 'chofer' => 'Chofer', 'direccionDestino' => 'DirDest',
        'comunaDestino' => 'CmnaDest', 'ciudadDestino' => 'CiudadDest', 'aduana' => 'Aduana', 'fechaSalida' => 'FchSalida', 'horaSalida' => 'HraSalida',
        'fechaLlegada' => 'FchLlegada',
    ];
    private const ALIAS_REFERENCIA = [
        'tipoDocumento' => 'TpoDocRef', 'folio' => 'FolioRef', 'fecha' => 'FchRef', 'codigo' => 'CodRef', 'razon' => 'RazonRef', 'global' => 'IndGlobal', 'rutOtro' => 'RUTOtr',
    ];
    private const ALIAS_DSCRCG = ['tipo' => 'TpoMov', 'valorTipo' => 'TpoValor', 'valor' => 'ValorDR', 'glosa' => 'GlosaDR', 'exento' => 'IndExeDR'];

    public function __construct(private readonly float $tasaIva = 19.0, private readonly bool $truncar = false)
    {
    }

    /** @param array<string, mixed> $data Ver la descripción de la clase. */
    public function buildFacturaElectronica(array $data): DOMDocument
    {
        return $this->build(DocumentType::FACTURA_ELECTRONICA, $data);
    }

    /** @param array<string, mixed> $data Ver la descripción de la clase. */
    public function build(int $documentType, array $data): DOMDocument
    {
        if (DocumentType::isBoleta($documentType)) {
            throw new InvalidArgumentException("El tipo {$documentType} (boleta) tiene su propio esquema y envío: use BoletaBuilder.");
        }
        $variante = $this->variante($documentType);
        $this->validate($data);
        // Reglas del formato del SII que el esquema no expresa: las notas de crédito y débito deben referenciar el documento que modifican,
        // y la guía de despacho debe indicar el tipo de traslado.
        if (in_array($documentType, [DocumentType::NOTA_DEBITO, DocumentType::NOTA_CREDITO, DocumentType::NOTA_DEBITO_EXPORTACION, DocumentType::NOTA_CREDITO_EXPORTACION], true) && empty($data['referencias'])) {
            throw new InvalidArgumentException('Las notas de crédito y de débito deben incluir al menos una referencia («referencias») al documento que corrigen o anulan.');
        }
        if ($documentType === DocumentType::GUIA_DESPACHO && !isset($data['indicadorTraslado']) && !isset($data['idDoc']['IndTraslado'])) {
            throw new InvalidArgumentException('La guía de despacho debe indicar el tipo de traslado («indicadorTraslado», 1 a 9).');
        }

        $emisor = $this->alias((array) $data['emisor'], self::ALIAS_EMISOR);
        $emisor['RUTEmisor'] = Rut::toDteFormat((string) $emisor['RUTEmisor']);
        $receptor = $this->alias((array) $data['receptor'], self::ALIAS_RECEPTOR);
        $receptor['RUTRecep'] = Rut::toDteFormat((string) $receptor['RUTRecep']);

        $detalle = array_map(fn (array $item): array => $this->item($item), array_values((array) $data['items']));
        $dscRcg = array_map(fn (array $d): array => $this->alias($d, self::ALIAS_DSCRCG), array_values((array) ($data['descuentosRecargos'] ?? [])));
        $exportacion = in_array($documentType, [DocumentType::FACTURA_EXPORTACION, DocumentType::NOTA_DEBITO_EXPORTACION, DocumentType::NOTA_CREDITO_EXPORTACION], true);
        $exenta = $exportacion || $documentType === DocumentType::FACTURA_NO_AFECTA_EXENTA;
        if ($documentType === DocumentType::FACTURA_NO_AFECTA_EXENTA) {
            // En la factura exenta cada línea lleva IndExe = 1 (es lo que el SII espera ver aunque todo el documento sea exento).
            $detalle = array_map(static fn (array $l): array => isset($l['IndExe']) ? $l : ['IndExe' => 1] + $l, $detalle);
        }
        if ($documentType === DocumentType::LIQUIDACION_FACTURA && !isset($data['totales'])) {
            throw new InvalidArgumentException('La liquidación-factura (43) lleva comisiones y cargos que no se calculan automáticamente: entregue el bloque «totales» completo.');
        }
        $calculo = (new Totals($this->tasaIva, $exportacion ? 2 : 0, $exenta))->calcular($detalle, $dscRcg, $this->tasas((array) ($data['impuestos'] ?? [])));

        $idDoc = ['TipoDTE' => $documentType, 'Folio' => $data['folio'], 'FchEmis' => $data['fechaEmision']]
            + $this->alias(array_intersect_key($data, self::ALIAS_ID_DOC), self::ALIAS_ID_DOC)
            + (array) ($data['idDoc'] ?? []);

        $encabezado = ['IdDoc' => $idDoc, 'Emisor' => $emisor, 'Receptor' => $receptor];
        if (isset($data['transporte'])) {
            $encabezado['Transporte'] = $this->transporte((array) $data['transporte']);
        }
        $encabezado['Totales'] = isset($data['totales']) ? (array) $data['totales'] : $calculo['totales'];
        if ($exportacion && isset($data['moneda'])) {
            $encabezado['Totales'] = ['TpoMoneda' => $data['moneda']] + $encabezado['Totales']; // la moneda va primero en los Totales de exportación
        }
        if (isset($data['otraMoneda'])) {
            $encabezado['OtraMoneda'] = $data['otraMoneda'];
        }

        $cuerpo = ['@ID' => "DTE_T{$documentType}F{$data['folio']}", 'Encabezado' => $encabezado, 'Detalle' => $calculo['detalle']];
        foreach (['subtotales' => 'SubTotInfo', 'comisiones' => 'Comisiones', 'geoReferencia' => 'GeoRefEmision', 'manejoMadera' => 'ManejoMadera'] as $alias => $sii) {
            if (isset($data[$alias])) {
                $cuerpo[$sii] = $data[$alias];
            }
        }
        if ($calculo['dscRcg'] !== []) {
            $cuerpo['DscRcgGlobal'] = $calculo['dscRcg'];
        }
        if (!empty($data['referencias'])) {
            $cuerpo['Referencia'] = array_map(
                fn (array $r, int $i): array => ['NroLinRef' => $i + 1] + $this->alias($r, self::ALIAS_REFERENCIA),
                array_values((array) $data['referencias']),
                array_keys(array_values((array) $data['referencias']))
            );
        }

        $doc = new DOMDocument('1.0', 'ISO-8859-1');
        $doc->formatOutput = false; // el espaciado extra rompería la canonicalización C14N de la firma
        $xml = new SpecXml(self::NAMESPACE_URI, ['TED', 'TmstFirma'], $this->truncar);
        $raiz = $xml->element($doc, Spec::load('dte'), ['@version' => '1.0', $variante => $cuerpo], 'DTE');
        // El <EnvioDTE> declara xmlns:xsi en su raíz y la canonicalización C14N «inclusiva» de <Documento> arrastra los espacios de nombres en alcance:
        // si el DTE se firma sin xsi y luego se inserta en el sobre, su resumen cambia y la firma queda inválida. Declarándolo aquí el contexto es el mismo.
        $raiz->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xsi', self::XSI);
        $doc->appendChild($raiz);

        return $doc;
    }

    /**
     * El monto total del documento, tal como quedó en <Totales><MntTotal>: el timbre (TedSigner::sign()) lo necesita en $data['montoTotal'].
     */
    public function montoTotal(DOMDocument $dteDocument): int
    {
        $nodes = $dteDocument->getElementsByTagName('MntTotal');
        if ($nodes->length === 0) {
            throw new RuntimeException('El documento no tiene <MntTotal> — ¿se generó con DteBuilder::build()?');
        }

        return (int) round((float) $nodes->item(0)->textContent); // en exportación el monto trae decimales y el timbre (MNT) es entero
    }

    /**
     * Incorpora el TED ya firmado (ver TedSigner::sign()) al <Documento>, junto con la marca de tiempo de la firma: deja el DTE listo para la firma
     * XMLDSig del <Documento> completo con el certificado de la empresa.
     */
    public function attachTed(DOMDocument $dteDocument, DOMDocument $tedDocument): void
    {
        $documento = $dteDocument->documentElement?->firstChild;
        if ($documento === null || !in_array($documento->nodeName, ['Documento', 'Liquidacion', 'Exportaciones'], true)) {
            throw new RuntimeException('El DTE no tiene un nodo <Documento> — ¿se generó con DteBuilder::build()?');
        }

        $tedElement = $tedDocument->documentElement;
        if ($tedElement === null) {
            throw new InvalidArgumentException('El documento del TED está vacío.');
        }

        $documento->appendChild($dteDocument->importNode($tedElement, true));

        // Hora de Chile explícita: es la que el SII espera ver como momento en que se firmó el documento, no la del servidor en UTC.
        $tmstFirma = (new DateTimeImmutable('now', new DateTimeZone('America/Santiago')))->format('Y-m-d\TH:i:s');
        $documento->appendChild(Xml::el($dteDocument, 'TmstFirma', $tmstFirma));
    }

    /** Elemento de la especificación («Documento», «Liquidacion» o «Exportaciones») cuyo TipoDTE admite este código. */
    private function variante(int $tipo): string
    {
        $dte = Spec::load('dte');
        foreach (['Documento', 'Liquidacion', 'Exportaciones'] as $v) {
            $nodo = Spec::child($dte, $v);
            $enum = Spec::child(Spec::child(Spec::child($nodo ?? [], 'Encabezado') ?? [], 'IdDoc') ?? [], 'TipoDTE')['type']['enum'] ?? [];
            if (in_array((string) $tipo, $enum, true)) {
                return $v;
            }
        }

        throw new InvalidArgumentException("El tipo de documento {$tipo} no existe en el esquema DTE del SII (válidos: 33, 34, 43, 46, 52, 56, 61, 110, 111, 112).");
    }

    /** @param array<string, mixed> $data */
    private function validate(array $data): void
    {
        foreach (['folio', 'fechaEmision', 'emisor', 'receptor', 'items'] as $field) {
            if (!isset($data[$field]) || $data[$field] === '' || $data[$field] === []) {
                throw new InvalidArgumentException("Falta el campo requerido \"{$field}\".");
            }
        }
        if ((int) $data['folio'] < 1) {
            throw new InvalidArgumentException('El folio debe ser un número entero positivo.');
        }
        $f = (string) $data['fechaEmision'];
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $f, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw new InvalidArgumentException('La fecha de emisión debe ser una fecha válida con el formato AAAA-MM-DD.');
        }
        if (!is_array($data['items']) || count($data['items']) === 0) {
            throw new InvalidArgumentException('El documento debe tener al menos un ítem en el detalle.');
        }
        foreach (['emisor' => ['rut', 'RUTEmisor', 'emisor'], 'receptor' => ['rut', 'RUTRecep', 'receptor']] as $bloque => [$amigable, $sii, $nombre]) {
            $rut = $data[$bloque][$amigable] ?? $data[$bloque][$sii] ?? null;
            if ($rut === null || $rut === '') {
                throw new InvalidArgumentException("Falta el RUT del {$nombre}.");
            }
            if (!Rut::isValid((string) $rut)) {
                throw new InvalidArgumentException("El RUT del {$nombre} no es válido (dígito verificador incorrecto).");
            }
        }
    }

    /** @param array<string, mixed> $item @return array<string, mixed> */
    private function item(array $item): array
    {
        $item = $this->alias($item, self::ALIAS_ITEM);
        if (array_key_exists('IndExe', $item)) {
            $item['IndExe'] = $item['IndExe'] === true ? 1 : ($item['IndExe'] === false ? null : $item['IndExe']);
        }
        if (isset($item['CdgItem'])) {
            $item['CdgItem'] = array_map(fn (array $c): array => $this->alias($c, ['tipo' => 'TpoCodigo', 'valor' => 'VlrCodigo']), array_values((array) $item['CdgItem']));
        }
        if (isset($item['CodImpAdic'])) {
            $item['CodImpAdic'] = array_map('strval', (array) $item['CodImpAdic']);
        }

        return $item;
    }

    /** @param array<string, mixed> $t @return array<string, mixed> */
    private function transporte(array $t): array
    {
        $t = $this->alias($t, self::ALIAS_TRANSPORTE);
        if (isset($t['Chofer'])) {
            $t['Chofer'] = $this->alias((array) $t['Chofer'], ['rut' => 'RUTChofer', 'nombre' => 'NombreChofer']);
        }
        if (isset($t['RUTTrans'])) {
            $t['RUTTrans'] = Rut::toDteFormat((string) $t['RUTTrans']);
        }

        return $t;
    }

    /**
     * @param array<string|int, mixed> $impuestos
     * @return array<string, float|int>
     */
    private function tasas(array $impuestos): array
    {
        $out = [];
        foreach ($impuestos as $codigo => $tasa) {
            $out[(string) $codigo] = $tasa;
        }

        return $out;
    }

    /**
     * Cambia los nombres amigables por los del SII; una clave que ya es un nombre del SII pasa tal cual. Un valor null o vacío se omite.
     *
     * @param array<string, mixed> $in
     * @param array<string, string> $map
     * @return array<string, mixed>
     */
    private function alias(array $in, array $map): array
    {
        $out = [];
        foreach ($in as $k => $v) {
            if ($v === null || $v === '') {
                continue;
            }
            $out[$map[$k] ?? $k] = $v;
        }

        return $out;
    }
}
