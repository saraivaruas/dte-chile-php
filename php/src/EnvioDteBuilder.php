<?php

declare(strict_types=1);

namespace IngeInnova\DteChile;

use DateTimeImmutable;
use DateTimeZone;
use DOMDocument;
use DOMElement;
use InvalidArgumentException;
use RuntimeException;

/**
 * Fase 4b (parte 1) del pipeline de DTE: envuelve uno o más <DTE> ya
 * firmados (ver DteSigner::sign()) en un <EnvioDTE>, con su <Caratula> y su
 * propia firma XMLDSig sobre el <SetDTE> completo — el archivo que
 * efectivamente se sube al SII vía SiiClient::uploadEnvioDte(). Estructura
 * y ejemplo confirmados contra el ejemplo oficial del SII
 * (sii.cl/servicios_online/docs/ejem_upload.txt) y el XSD EnvioDTE_v10.xsd.
 */
final class EnvioDteBuilder
{
    private const NAMESPACE_URI = 'http://www.sii.cl/SiiDte';
    private const XSI_NAMESPACE = 'http://www.w3.org/2001/XMLSchema-instance';

    /** RUT genérico del SII — receptor de todo envío hecho directamente al SII (no a otro contribuyente). */
    private const SII_RUT = '60803000-K';

    public function __construct(private readonly DteSigner $signer = new DteSigner())
    {
    }

    /**
     * @param DOMDocument[] $signedDtes Documentos <DTE> ya firmados (DteSigner::sign()), del mismo emisor.
     * @param array{
     *   rutEmisor: string,
     *   rutEnvia: string,
     *   fechaResolucion: string,
     *   numeroResolucion: int,
     * } $caratula 'rutEnvia' es el RUT del titular del certificado que firma
     *   el envío (el "firmante autorizado"), no necesariamente el mismo RUT
     *   que 'rutEmisor' si quien envía es un mandatario de la empresa.
     *   'fechaResolucion'/'numeroResolucion' son los datos de la resolución
     *   del SII que autorizó al emisor como facturador electrónico (0 si la
     *   autorización fue por internet, según el instructivo del SII).
     * @throws InvalidArgumentException Si no se recibe ningún DTE, o alguno no tiene la forma esperada.
     */
    public function build(array $signedDtes, array $caratula, Certificate $certificate): DOMDocument
    {
        return $this->armar('EnvioDTE', 'EnvioDTE_v10.xsd', $signedDtes, $caratula, $certificate);
    }

    /**
     * Igual que {@see build()}, pero para boletas (39 y 41): el SII las recibe en un `<EnvioBOLETA>` con su propio esquema (hasta 500 por envío).
     *
     * @param DOMDocument[] $signedBoletas Boletas firmadas (BoletaBuilder + TedSigner + DteSigner::sign()).
     * @param array<string, mixed> $caratula Mismos datos que build().
     */
    public function buildBoleta(array $signedBoletas, array $caratula, Certificate $certificate): DOMDocument
    {
        foreach ($signedBoletas as $i => $b) {
            $tipo = (int) ($b->getElementsByTagName('TipoDTE')->item(0)?->textContent ?? 0);
            if (!DocumentType::isBoleta($tipo)) {
                throw new InvalidArgumentException("El documento en la posición {$i} es de tipo {$tipo}: el EnvioBOLETA solo admite boletas (39 y 41).");
            }
        }

        return $this->armar('EnvioBOLETA', 'EnvioBOLETA_v11.xsd', $signedBoletas, $caratula, $certificate);
    }

    /** @param DOMDocument[] $signedDtes @param array<string, mixed> $caratula */
    private function armar(string $raiz, string $esquema, array $signedDtes, array $caratula, Certificate $certificate): DOMDocument
    {
        if ($signedDtes === []) {
            throw new InvalidArgumentException('El envío debe contener al menos un DTE.');
        }

        $doc = new DOMDocument('1.0', 'ISO-8859-1');

        $envioDte = $doc->createElementNS(self::NAMESPACE_URI, $raiz);
        $envioDte->setAttributeNS(self::XSI_NAMESPACE, 'xsi:schemaLocation', 'http://www.sii.cl/SiiDte ' . $esquema);
        $envioDte->setAttribute('version', '1.0');
        $doc->appendChild($envioDte);

        $setDte = $doc->createElement('SetDTE');
        $setDte->setAttribute('ID', 'SetDoc');
        $envioDte->appendChild($setDte);

        $setDte->appendChild($this->buildCaratula($doc, $caratula, $signedDtes));

        // Los DTE ya firmados se insertan como TEXTO, tal cual fueron firmados. Ni importNode() ni appendXML() sirven: libxml «reconcilia» los espacios
        // de nombres y deja un `xmlns:default` sobrante en <DTE>, que cambia la forma canónica de <Documento> y rompe la firma de cada DTE.
        $dtesXml = '';
        foreach ($signedDtes as $index => $signedDte) {
            $documentElement = $signedDte->documentElement;
            if ($documentElement === null || $documentElement->tagName !== 'DTE') {
                throw new InvalidArgumentException("El DTE en la posición {$index} no es un documento válido (¿se firmó con DteSigner::sign()?).");
            }
            $dtesXml .= (string) $signedDte->saveXML($documentElement); // sin declaración XML, en UTF-8
        }
        $xml = (string) $doc->saveXML(); // declarado ISO-8859-1: los DTE (UTF-8) se convierten para no mezclar codificaciones
        $xml = str_replace('</SetDTE>', mb_convert_encoding($dtesXml, 'ISO-8859-1', 'UTF-8') . '</SetDTE>', $xml);

        $doc = new DOMDocument();
        if (!$doc->loadXML($xml)) {
            throw new RuntimeException('No fue posible armar el sobre de envío con los DTE firmados.');
        }

        return $this->signer->signElement($doc, 'SetDTE', $certificate);
    }

    /** @param array<string, mixed> $caratula Ver build(). @param DOMDocument[] $signedDtes */
    private function buildCaratula(DOMDocument $doc, array $caratula, array $signedDtes): DOMElement
    {
        $carat = $doc->createElement('Caratula');
        $carat->setAttribute('version', '1.0');

        $carat->appendChild(Xml::el($doc, 'RutEmisor', Rut::toDteFormat((string) $caratula['rutEmisor'])));
        $carat->appendChild(Xml::el($doc, 'RutEnvia', Rut::toDteFormat((string) $caratula['rutEnvia'])));
        $carat->appendChild(Xml::el($doc, 'RutReceptor', self::SII_RUT));
        $carat->appendChild(Xml::el($doc, 'FchResol', (string) $caratula['fechaResolucion']));
        $carat->appendChild(Xml::el($doc, 'NroResol', (string) $caratula['numeroResolucion']));

        // Hora de Chile explícita — es la que el SII espera ver como
        // momento en que se firmó el archivo de envío.
        $tmstFirmaEnv = (new DateTimeImmutable('now', new DateTimeZone('America/Santiago')))->format('Y-m-d\TH:i:s');
        $carat->appendChild(Xml::el($doc, 'TmstFirmaEnv', $tmstFirmaEnv));

        foreach ($this->countByDocumentType($signedDtes) as $tipo => $cantidad) {
            $subTot = $doc->createElement('SubTotDTE');
            $subTot->appendChild(Xml::el($doc, 'TpoDTE', (string) $tipo));
            $subTot->appendChild(Xml::el($doc, 'NroDTE', (string) $cantidad));
            $carat->appendChild($subTot);
        }

        return $carat;
    }

    /**
     * @param DOMDocument[] $signedDtes
     * @return array<int, int> tipo de documento => cantidad de DTE de ese tipo en el envío
     */
    private function countByDocumentType(array $signedDtes): array
    {
        $counts = [];
        foreach ($signedDtes as $signedDte) {
            $tipoNodes = $signedDte->getElementsByTagName('TipoDTE');
            if ($tipoNodes->length === 0) {
                throw new InvalidArgumentException('Uno de los DTE recibidos no tiene <TipoDTE> — ¿se generó con DteBuilder?');
            }
            $tipo = (int) $tipoNodes->item(0)->textContent;
            $counts[$tipo] = ($counts[$tipo] ?? 0) + 1;
        }

        return $counts;
    }
}
