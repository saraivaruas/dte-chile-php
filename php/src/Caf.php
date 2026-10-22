<?php

declare(strict_types=1);

namespace IngeInnova\DteChile;

use InvalidArgumentException;
use OpenSSLAsymmetricKey;
use RuntimeException;

/**
 * CAF (Código de Autorización de Folios) descargado del SII — trae, además
 * del rango de folios autorizado, un par de llaves RSA propio (RSASK/RSAPUBK)
 * que se usa para firmar el TED (Timbre Electrónico) de cada documento, sin
 * relación con el certificado digital de la empresa.
 */
final class Caf
{
    private function __construct(private readonly \SimpleXMLElement $xml)
    {
    }

    /** @throws InvalidArgumentException Si el XML no tiene la forma de un CAF real del SII. */
    public static function fromXml(string $rawXml): self
    {
        // No se deja cambiado el ajuste global de libxml para el resto de la aplicación, y no se permite acceso a la red al leer el XML.
        $anterior = libxml_use_internal_errors(true);
        try {
            $doc = simplexml_load_string($rawXml, \SimpleXMLElement::class, LIBXML_NONET);
            libxml_clear_errors();
        } finally {
            libxml_use_internal_errors($anterior);
        }
        if ($doc === false) {
            throw new InvalidArgumentException('El archivo no es un XML válido.');
        }

        $nodes = $doc->xpath('//DA');
        $da = $nodes[0] ?? null;
        // <RSASK> (llave privada del CAF) es hermano de <CAF>, no hijo de
        // <DA> — vive en <AUTORIZACION><CAF>...</CAF><RSASK>...</RSASK></AUTORIZACION>.
        $rsask = $doc->xpath('//RSASK');
        if (!$da || !isset($da->TD, $da->RNG->D, $da->RNG->H) || empty($rsask)) {
            throw new InvalidArgumentException('El archivo no tiene el formato de un CAF válido del SII.');
        }

        return new self($doc);
    }

    private function da(): \SimpleXMLElement
    {
        return $this->xml->xpath('//DA')[0];
    }

    public function documentType(): int
    {
        return (int) $this->da()->TD;
    }

    public function folioFrom(): int
    {
        return (int) $this->da()->RNG->D;
    }

    public function folioTo(): int
    {
        return (int) $this->da()->RNG->H;
    }

    public function containsFolio(int $folio): bool
    {
        return $folio >= $this->folioFrom() && $folio <= $this->folioTo();
    }

    /** RUT de la empresa emisora a la que el SII le autorizó este rango de folios. */
    public function issuerRut(): string
    {
        return Rut::toDteFormat((string) $this->da()->RE);
    }

    public function issuerName(): string
    {
        return (string) $this->da()->RS;
    }

    /** El nodo <CAF>...</CAF> completo (con <DA> y <FRMA>) tal como lo pide el TED — no el <AUTORIZACION> que lo envuelve. */
    public function cafXmlNode(): string
    {
        $nodes = $this->xml->xpath('//CAF');
        if (!isset($nodes[0])) {
            throw new RuntimeException('No fue posible ubicar el nodo <CAF> dentro del archivo.');
        }

        // SimpleXMLElement::asXML() antepone la declaración XML inicial
        // (version/encoding) — el TED necesita solo el fragmento, sin esa cabecera.
        $xml = $nodes[0]->asXML();
        return preg_replace('/^<\?xml[^?]*\?>\s*/', '', (string) $xml) ?? (string) $xml;
    }

    /** Llave privada propia del CAF (RSASK) — firma el TED, nada que ver con el certificado digital de la empresa. */
    public function privateKey(): OpenSSLAsymmetricKey
    {
        $pem = (string) $this->xml->xpath('//RSASK')[0];
        $key = openssl_pkey_get_private($pem);
        if ($key === false) {
            throw new RuntimeException('No fue posible leer la llave privada del CAF (RSASK).');
        }

        return $key;
    }

    public function idk(): int
    {
        return (int) $this->da()->IDK;
    }
}
