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
 * Fase 2 del pipeline de DTE: arma y firma el TED (Timbre Electrónico) —
 * el bloque que se imprime como PDF417 en el documento. Se firma con la
 * llave RSA propia del CAF (RSASK), NO con el certificado digital de la
 * empresa — esa es una firma completamente distinta (XMLDSig sobre el DTE
 * completo, fase 3, todavía no escrita).
 */
final class TedSigner
{
    /**
     * @param array{
     *   folio: int,
     *   fechaEmision: string,
     *   emisor: array{rut: string},
     *   receptor: array{rut: string, razonSocial: string},
     *   items: array<int, array{nombre: string}>,
     *   montoTotal: int,
     * } $data Mismo array que recibe DteBuilder::build(), más 'montoTotal'
     *   (el monto final ya calculado — el TED se arma después de conocer
     *   los totales, así que quien llama debe calcularlo primero, por
     *   ejemplo con el mismo DOMDocument que devolvió DteBuilder).
     * @throws InvalidArgumentException Si el folio no está autorizado por este CAF, o el CAF es de otro tipo de documento.
     */
    public function sign(Caf $caf, int $documentType, array $data): DOMDocument
    {
        $folio = (int) $data['folio'];
        if ($documentType !== $caf->documentType()) {
            throw new InvalidArgumentException(
                "El CAF cargado es para el tipo de documento {$caf->documentType()}, no {$documentType}."
            );
        }
        if (!$caf->containsFolio($folio)) {
            throw new InvalidArgumentException(
                "El folio {$folio} no está autorizado por este CAF (rango {$caf->folioFrom()}-{$caf->folioTo()})."
            );
        }

        $doc = new DOMDocument('1.0', 'ISO-8859-1');
        $doc->formatOutput = false;

        $ted = $doc->createElement('TED');
        $ted->setAttribute('version', '1.0');
        $doc->appendChild($ted);

        $dd = $this->buildDd($doc, $documentType, $folio, $caf, $data);
        $ted->appendChild($dd);
        $ted->appendChild($this->buildFrmt($doc, $dd, $caf));

        return $doc;
    }

    /** @param array<string, mixed> $data Ver sign(). */
    private function buildDd(DOMDocument $doc, int $documentType, int $folio, Caf $caf, array $data): DOMElement
    {
        $dd = $doc->createElement('DD');

        $dd->appendChild(Xml::el($doc, 'RE', Rut::toDteFormat($data['emisor']['rut'])));
        $dd->appendChild(Xml::el($doc, 'TD', (string) $documentType));
        $dd->appendChild(Xml::el($doc, 'F', (string) $folio));
        $dd->appendChild(Xml::el($doc, 'FE', $data['fechaEmision']));
        // En las boletas el receptor es opcional: sin RUT va el genérico 66666666-6 y sin nombre «Cliente».
        $dd->appendChild(Xml::el($doc, 'RR', Rut::toDteFormat((string) ($data['receptor']['rut'] ?? BoletaBuilder::RUT_GENERICO))));
        $razon = trim((string) ($data['receptor']['razonSocial'] ?? ''));
        $dd->appendChild(Xml::el($doc, 'RSR', $this->truncate($razon !== '' ? $razon : 'Cliente', 40)));
        $dd->appendChild(Xml::el($doc, 'MNT', (string) $data['montoTotal']));

        $primerItem = (string) ($data['items'][0]['nombre'] ?? '');
        $dd->appendChild(Xml::el($doc, 'IT1', $this->truncate($primerItem, 40)));

        // El nodo <CAF>...</CAF> completo (con <DA> y <FRMA>) tal como lo exige el TED.
        $cafFragment = $doc->createDocumentFragment();
        $cafFragment->appendXML($caf->cafXmlNode());
        $dd->appendChild($cafFragment);

        // Hora de Chile explícita (no la del servidor) — es la fecha/hora que
        // el SII espera ver impresa junto con el resto del documento.
        $tsted = (new DateTimeImmutable('now', new DateTimeZone('America/Santiago')))->format('Y-m-d\TH:i:s');
        $dd->appendChild(Xml::el($doc, 'TSTED', $tsted));

        return $dd;
    }

    private function buildFrmt(DOMDocument $doc, DOMElement $dd, Caf $caf): DOMElement
    {
        $ddXml = $doc->saveXML($dd);
        if ($ddXml === false) {
            throw new RuntimeException('No fue posible serializar el nodo <DD> del TED.');
        }

        // El timbre NO se firma con C14N/XMLDSig estándar — el instructivo
        // del SII (Anexo A.2.4) exige eliminar todo el espacio entre tags
        // antes de firmar, sin tocar el contenido de los elementos
        // terminales. Sin esto, un CAF real (que trae indentación propia)
        // produciría una firma que el SII no podría verificar, aunque el
        // nodo <CAF> en sí no se modifique — solo cambian los bytes que se
        // firman, no lo que queda embebido en el documento final.
        $ddXmlForSigning = preg_replace('/>\s+</', '><', $ddXml);
        // saveXML() de un nodo devuelve UTF-8, pero el documento se transmite en ISO-8859-1 y el SII verifica el timbre sobre esos bytes: se firma lo
        // mismo que viaja (con «í» como un solo byte, no dos). Sin esto toda tilde o ñ dejaba el timbre con firma inválida.
        if (mb_check_encoding($ddXmlForSigning, 'UTF-8')) {
            $ddXmlForSigning = mb_convert_encoding($ddXmlForSigning, 'ISO-8859-1', 'UTF-8');
        }

        $signature = '';
        $signed = openssl_sign($ddXmlForSigning, $signature, $caf->privateKey(), OPENSSL_ALGO_SHA1);
        if (!$signed) {
            throw new RuntimeException('No fue posible firmar el TED con la llave privada del CAF (RSASK).');
        }

        $frmt = Xml::el($doc, 'FRMT', base64_encode($signature));
        $frmt->setAttribute('algoritmo', 'SHA1withRSA');

        return $frmt;
    }

    private function truncate(string $value, int $maxLength): string
    {
        return mb_substr(trim($value), 0, $maxLength);
    }
}
