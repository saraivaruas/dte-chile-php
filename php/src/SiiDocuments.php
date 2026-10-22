<?php

declare(strict_types=1);

namespace IngeInnova\DteChile;

use DateTimeImmutable;
use DateTimeZone;
use DOMDocument;
use InvalidArgumentException;
use RuntimeException;

/**
 * Documentos firmados del SII distintos del DTE: libros de compras y ventas, de guías y de boletas, consumo de folios (RCOF), respuestas de intercambio,
 * recibos de mercaderías y archivo electrónico de cesión (AEC).
 *
 * Todos se arman igual que el DTE: los datos se entregan con los nombres de elemento del SII (ver los esquemas oficiales en `schemas/`), {@see SpecXml}
 * los ordena y valida contra la especificación generada desde esos esquemas, y {@see DteSigner} los firma con el certificado digital. El identificador (`ID`)
 * y las marcas de tiempo (hora de Chile) se completan solos cuando no se entregan.
 */
final class SiiDocuments
{
    private const NS = DteBuilder::NAMESPACE_URI;
    private const XSI = 'http://www.w3.org/2001/XMLSchema-instance';

    public function __construct(private readonly DteSigner $signer = new DteSigner(), private readonly bool $truncar = false)
    {
    }

    /** Libro de compras y ventas (IECV). Datos: `['EnvioLibro' => ['Caratula' => [...], 'ResumenPeriodo' => [...], 'Detalle' => [[...]]]]`. */
    public function libroCompraVenta(array $data, Certificate $cert): DOMDocument
    {
        return $this->simple('libro_compra_venta', 'EnvioLibro', 'LibroCV_v10.xsd', 'LibroCV', $data, $cert, ['EnvioLibro', 'TmstFirma']);
    }

    /** Libro de guías de despacho. */
    public function libroGuia(array $data, Certificate $cert): DOMDocument
    {
        return $this->simple('libro_guia', 'EnvioLibro', 'LibroGuia_v10.xsd', 'LibroGuia', $data, $cert, ['EnvioLibro', 'TmstFirma']);
    }

    /** Libro de boletas electrónicas. */
    public function libroBoleta(array $data, Certificate $cert): DOMDocument
    {
        return $this->simple('libro_boleta', 'EnvioLibro', 'LibroBOLETA_v10.xsd', 'LibroBoleta', $data, $cert, ['EnvioLibro', 'TmstFirma']);
    }

    /** Reporte de consumo de folios (RCOF) de las boletas. Datos: `['DocumentoConsumoFolios' => ['Caratula' => [...], 'Resumen' => [[...]]]]`. */
    public function consumoFolios(array $data, Certificate $cert): DOMDocument
    {
        return $this->simple('consumo_folios', 'DocumentoConsumoFolios', 'ConsumoFolio_v10.xsd', 'ConsumoFolios', $data, $cert, ['DocumentoConsumoFolios', 'Caratula', 'TmstFirmaEnv']);
    }

    /** Respuesta de intercambio (recepción de envío, resultado comercial). Datos: `['Resultado' => ['Caratula' => [...], 'RecepcionEnvio' | 'ResultadoDTE' => ...]]`. */
    public function respuestaDte(array $data, Certificate $cert): DOMDocument
    {
        return $this->simple('respuesta_dte', 'Resultado', 'RespuestaEnvioDTE_v10.xsd', 'Respuesta', $data, $cert, ['Resultado', 'Caratula', 'TmstFirmaResp']);
    }

    /** Un recibo de mercaderías o servicios (Ley 19.983), firmado. Datos: `['DocumentoRecibo' => [TipoDoc, Folio, FchEmis, RUTEmisor, RUTRecep, MntTotal, Recinto, RutFirma, Declaracion]]`. */
    public function recibo(array $data, Certificate $cert): DOMDocument
    {
        return $this->simple('recibo', 'DocumentoRecibo', 'Recibos_v10.xsd', 'Recibo', $data, $cert, ['DocumentoRecibo', 'TmstFirmaRecibo']);
    }

    /**
     * Envío de recibos: agrupa varios recibos (cada uno firmado por separado) y firma el conjunto.
     *
     * @param array<string, mixed> $caratula RutResponde, RutRecibe, NmbContacto?, FonoContacto?, MailContacto?, TmstFirmaEnv? (por defecto, ahora)
     * @param array<int, array<string, mixed>> $recibos Datos de cada recibo (ver {@see recibo()}).
     */
    public function envioRecibos(array $caratula, array $recibos, Certificate $cert): DOMDocument
    {
        if ($recibos === []) {
            throw new InvalidArgumentException('El envío de recibos debe contener al menos un recibo.');
        }
        $firmados = [];
        foreach (array_values($recibos) as $i => $r) {
            $r['DocumentoRecibo']['@ID'] ??= 'Recibo' . ($i + 1);
            $firmados[] = $this->recibo($r, $cert);
        }
        $caratula['TmstFirmaEnv'] ??= $this->ahora();
        $data = ['SetRecibos' => ['@ID' => 'SetRecibos', 'Caratula' => $caratula]];
        $doc = $this->esqueleto('envio_recibos', $data, 'EnvioRecibos_v10.xsd', ['Recibo']);
        $doc = $this->insertar($doc, '</SetRecibos>', array_map(fn (DOMDocument $d): string => $this->fragmento($d), $firmados), false);

        return $this->signer->signElement($doc, 'SetRecibos', $cert);
    }

    /**
     * Archivo electrónico de cesión (AEC) de una factura.
     *
     * @param array<string, mixed> $caratula RutCedente, RutCesionario, NmbContacto?, FonoContacto?, MailContacto?, TmstFirmaEnvio? (por defecto, ahora)
     * @param DOMDocument $dte El DTE firmado que se cede (el mismo que se envió al SII).
     * @param array<int, array<string, mixed>> $cesiones Datos de cada cesión (`DocumentoCesion`: SeqCesion, IdDTE, Cedente, Cesionario, MontoCesion, UltimoVencimiento...). Hasta 40.
     * @param array<string, mixed> $dteCedido Extras opcionales de DocumentoDTECedido: ImagenDTE (base64), ImagenAR (base64).
     */
    public function aec(array $caratula, DOMDocument $dte, array $cesiones, Certificate $cert, array $dteCedido = []): DOMDocument
    {
        if ($cesiones === []) {
            throw new InvalidArgumentException('El AEC debe contener al menos una cesión.');
        }
        if ($dte->documentElement?->tagName !== 'DTE') {
            throw new InvalidArgumentException('El documento a ceder debe ser un DTE firmado.');
        }
        $cedido = $this->esqueleto('dte_cedido', ['DocumentoDTECedido' => ['@ID' => 'DTECedido'] + $dteCedido + ['TmstFirma' => $this->ahora()]], null, ['DTE']);
        $cedido = $this->insertar($cedido, '<DocumentoDTECedido ID="DTECedido">', [$this->fragmento($dte)], true);
        $cedido = $this->signer->signElement($cedido, 'DocumentoDTECedido', $cert);

        $firmadas = [];
        foreach (array_values($cesiones) as $i => $c) {
            $c['@ID'] ??= 'Cesion' . ($i + 1);
            $firmadas[] = $this->simple('cesion', 'DocumentoCesion', null, 'Cesion', ['DocumentoCesion' => $c], $cert, ['DocumentoCesion', 'TmstCesion']);
        }

        $caratula['TmstFirmaEnvio'] ??= $this->ahora();
        $aec = $this->esqueleto('aec', ['DocumentoAEC' => ['@ID' => 'AEC', 'Caratula' => $caratula]], 'AEC_v10.xsd', ['Cesiones']);
        $contenido = array_merge([$this->fragmento($cedido)], array_map(fn (DOMDocument $d): string => $this->fragmento($d), $firmadas));
        $doc = $this->insertar($aec, '</DocumentoAEC>', ['<Cesiones>' . implode('', $contenido) . '</Cesiones>'], false);

        return $this->signer->signElement($doc, 'DocumentoAEC', $cert);
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $tiempo Ruta (de claves) de la marca de tiempo que se completa con la hora de Chile si falta.
     */
    private function simple(string $spec, string $signTag, ?string $esquema, string $prefijoId, array $data, Certificate $cert, array $tiempo): DOMDocument
    {
        $data[$signTag]['@ID'] ??= $prefijoId . '_' . (new DateTimeImmutable('now', new DateTimeZone('America/Santiago')))->format('YmdHis');
        $ref = &$data;
        foreach (array_slice($tiempo, 0, -1) as $k) {
            $ref[$k] ??= [];
            $ref = &$ref[$k];
        }
        $ref[end($tiempo)] ??= $this->ahora();
        unset($ref);

        $doc = $this->esqueleto($spec, $data, $esquema);

        return $this->signer->signElement($doc, $signTag, $cert, $data[$signTag]['@ID']);
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $deferred
     */
    private function esqueleto(string $spec, array $data, ?string $esquema, array $deferred = []): DOMDocument
    {
        $raiz = Spec::load($spec);
        $doc = new DOMDocument('1.0', 'ISO-8859-1');
        $doc->formatOutput = false;
        $el = (new SpecXml(self::NS, $deferred, $this->truncar))->element($doc, $raiz, $data, (string) $raiz['name']);
        // Todos declaran xmlns:xsi: así la forma canónica (C14N inclusiva) de lo que se firma es la misma suelto y dentro de otro documento.
        $el->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xsi', self::XSI);
        if ($esquema !== null) {
            $el->setAttributeNS(self::XSI, 'xsi:schemaLocation', self::NS . ' ' . $esquema);
        }
        $doc->appendChild($el);

        return $doc;
    }

    /**
     * Inserta fragmentos XML ya firmados, como texto y sin reconciliar espacios de nombres (que cambiaría su forma canónica y rompería sus firmas).
     *
     * @param list<string> $fragmentos
     */
    private function insertar(DOMDocument $doc, string $ancla, array $fragmentos, bool $despues): DOMDocument
    {
        $xml = (string) $doc->saveXML();
        if (substr_count($xml, $ancla) !== 1) {
            throw new RuntimeException("No se encontró el punto de inserción {$ancla}.");
        }
        $texto = implode('', array_map(fn (string $f): string => $this->aIso($f), $fragmentos));
        $xml = str_replace($ancla, $despues ? $ancla . $texto : $texto . $ancla, $xml);
        $nuevo = new DOMDocument();
        if (!$nuevo->loadXML($xml)) {
            throw new RuntimeException('No fue posible armar el documento con los elementos firmados.');
        }

        return $nuevo;
    }

    /** El elemento raíz del documento, serializado (UTF-8, sin declaración XML). */
    private function fragmento(DOMDocument $d): string
    {
        return (string) $d->saveXML($d->documentElement);
    }

    private function aIso(string $utf8): string
    {
        return mb_convert_encoding($utf8, 'ISO-8859-1', 'UTF-8');
    }

    private function ahora(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('America/Santiago')))->format('Y-m-d\TH:i:s');
    }
}
