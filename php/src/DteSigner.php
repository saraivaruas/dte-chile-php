<?php

declare(strict_types=1);

namespace IngeInnova\DteChile;

use DOMDocument;
use DOMElement;
use RuntimeException;

/**
 * Fase 3 del pipeline de DTE: firma XMLDSig el <Documento> completo
 * (Encabezado + Detalle + TED, ya armados por las fases 1-2) con el
 * certificado digital de la empresa — una firma independiente y posterior
 * a la del TED, que usa el par de llaves del certificado, no el del CAF.
 * signElement() generaliza esta misma mecánica para firmar otros nodos por
 * ID (usado por EnvioDteBuilder para firmar <SetDTE>).
 */
final class DteSigner
{
    private const XMLDSIG_NAMESPACE = 'http://www.w3.org/2000/09/xmldsig#';
    private const C14N_ALGORITHM = 'http://www.w3.org/TR/2001/REC-xml-c14n-20010315';
    private const SIGNATURE_METHOD = 'http://www.w3.org/2000/09/xmldsig#rsa-sha1';
    private const DIGEST_METHOD = 'http://www.w3.org/2000/09/xmldsig#sha1';

    /** @throws RuntimeException Si el <Documento> no existe, no tiene atributo ID, o la firma con OpenSSL falla. */
    public function sign(DOMDocument $dteDocument, Certificate $certificate): DOMDocument
    {
        // El nodo firmado es el hijo directo de <DTE>: «Documento» en los documentos comunes, «Exportaciones» en los de exportación y «Liquidacion» en la
        // liquidación-factura (el esquema del SII los nombra distinto, pero todos llevan el atributo ID y el mismo tratamiento).
        $nombre = $dteDocument->documentElement?->firstChild?->nodeName;
        if (!in_array($nombre, ['Documento', 'Exportaciones', 'Liquidacion'], true)) {
            throw new RuntimeException('El DTE no tiene un nodo <Documento>, <Exportaciones> o <Liquidacion> para firmar.');
        }

        return $this->signElement($dteDocument, $nombre, $certificate);
    }

    /**
     * Firma XMLDSig cualquier elemento identificado por nombre de tag y
     * atributo ID dentro del documento — lo mismo que sign() hace para
     * <Documento>, generalizado para que EnvioDteBuilder pueda reusarlo al
     * firmar <SetDTE> (misma mecánica, distinto elemento referenciado).
     * @throws RuntimeException Si el elemento no existe, no tiene atributo ID, o la firma con OpenSSL falla.
     */
    public function signElement(DOMDocument $document, string $tagName, Certificate $certificate, ?string $id = null): DOMDocument
    {
        // Se re-serializa y re-parsea el documento antes de firmarlo: así el
        // C14N que se firma es exactamente el que va a recalcular cualquiera
        // que reciba el XML final (el SII incluido) al parsear los bytes que
        // en efecto le enviamos — sin depender de cómo quedó armado el árbol
        // DOM en memoria durante las fases previas.
        $xml = $document->saveXML();
        if ($xml === false) {
            throw new RuntimeException('No fue posible serializar el documento antes de firmarlo.');
        }

        $doc = new DOMDocument();
        $doc->loadXML($xml);

        // Con $id se firma el elemento con ese ID (hay documentos con varios del mismo nombre, como los <Recibo> de un EnvioRecibos);
        // sin $id, el primero. La firma queda como último hijo del padre del elemento firmado (<DTE>, <EnvioDTE>, <Cesion>, <AEC>...).
        $target = null;
        foreach ($doc->getElementsByTagName($tagName) as $candidato) {
            if ($id === null || $candidato->getAttribute('ID') === $id) {
                $target = $candidato;
                break;
            }
        }
        if ($target === null) {
            throw new RuntimeException("El documento no tiene un nodo <{$tagName}>" . ($id !== null ? " con ID {$id}" : '') . ' para firmar.');
        }

        $id = $target->getAttribute('ID');
        if ($id === '') {
            throw new RuntimeException("El nodo <{$tagName}> no tiene atributo ID — no se puede referenciar desde la firma.");
        }

        $digestValue = base64_encode(sha1($target->C14N(), true));

        $this->buildSignature($doc, $target->parentNode, $id, $digestValue, $certificate);

        return $doc;
    }

    private function buildSignature(DOMDocument $doc, \DOMNode $parent, string $id, string $digestValue, Certificate $certificate): void
    {
        $ns = self::XMLDSIG_NAMESPACE;

        // <Signature> se adjunta al árbol ANTES de canonicalizar <SignedInfo>
        // — el C14N (no exclusivo) que exige XMLDSig arrastra los namespaces
        // en scope de los ancestros (el xmlns por defecto de <DTE>). Si se
        // firmara con <Signature> todavía desconectado del documento, el
        // C14N calculado acá no coincidiría con el que recalcula cualquier
        // verificador sobre el XML final, e invalidaría la firma.
        $signature = $doc->createElementNS($ns, 'Signature');
        $parent->appendChild($signature);

        $signedInfo = $this->buildSignedInfo($doc, $id, $digestValue);
        $signature->appendChild($signedInfo);

        // El SignatureValue se firma sobre la forma canónica de SignedInfo,
        // no sobre el <Documento> directamente — así lo exige XMLDSig.
        $signatureValueRaw = '';
        $signed = openssl_sign($signedInfo->C14N(), $signatureValueRaw, $certificate->privateKey(), OPENSSL_ALGO_SHA1);
        if (!$signed) {
            throw new RuntimeException('No fue posible firmar el DTE con la llave privada del certificado.');
        }
        $signature->appendChild($doc->createElementNS($ns, 'SignatureValue', base64_encode($signatureValueRaw)));

        $signature->appendChild($this->buildKeyInfo($doc, $certificate));
    }

    private function buildSignedInfo(DOMDocument $doc, string $id, string $digestValue): DOMElement
    {
        $ns = self::XMLDSIG_NAMESPACE;

        $signedInfo = $doc->createElementNS($ns, 'SignedInfo');

        $canonicalizationMethod = $doc->createElementNS($ns, 'CanonicalizationMethod');
        $canonicalizationMethod->setAttribute('Algorithm', self::C14N_ALGORITHM);
        $signedInfo->appendChild($canonicalizationMethod);

        $signatureMethod = $doc->createElementNS($ns, 'SignatureMethod');
        $signatureMethod->setAttribute('Algorithm', self::SIGNATURE_METHOD);
        $signedInfo->appendChild($signatureMethod);

        $reference = $doc->createElementNS($ns, 'Reference');
        $reference->setAttribute('URI', '#' . $id);

        $transforms = $doc->createElementNS($ns, 'Transforms');
        $transform = $doc->createElementNS($ns, 'Transform');
        $transform->setAttribute('Algorithm', self::C14N_ALGORITHM);
        $transforms->appendChild($transform);
        $reference->appendChild($transforms);

        $digestMethod = $doc->createElementNS($ns, 'DigestMethod');
        $digestMethod->setAttribute('Algorithm', self::DIGEST_METHOD);
        $reference->appendChild($digestMethod);

        $reference->appendChild($doc->createElementNS($ns, 'DigestValue', $digestValue));

        $signedInfo->appendChild($reference);

        return $signedInfo;
    }

    private function buildKeyInfo(DOMDocument $doc, Certificate $certificate): DOMElement
    {
        $ns = self::XMLDSIG_NAMESPACE;

        $details = openssl_pkey_get_details($certificate->privateKey());
        if ($details === false || !isset($details['rsa']['n'], $details['rsa']['e'])) {
            throw new RuntimeException('No fue posible leer el módulo/exponente RSA del certificado.');
        }

        $keyInfo = $doc->createElementNS($ns, 'KeyInfo');

        $rsaKeyValue = $doc->createElementNS($ns, 'RSAKeyValue');
        $rsaKeyValue->appendChild($doc->createElementNS($ns, 'Modulus', base64_encode($details['rsa']['n'])));
        $rsaKeyValue->appendChild($doc->createElementNS($ns, 'Exponent', base64_encode($details['rsa']['e'])));

        $keyValue = $doc->createElementNS($ns, 'KeyValue');
        $keyValue->appendChild($rsaKeyValue);
        $keyInfo->appendChild($keyValue);

        $x509Data = $doc->createElementNS($ns, 'X509Data');
        $x509Data->appendChild($doc->createElementNS($ns, 'X509Certificate', $this->certificateToBase64($certificate)));
        $keyInfo->appendChild($x509Data);

        return $keyInfo;
    }

    /** El certificado en PEM ya ES base64 del DER — solo hace falta quitarle las cabeceras y los saltos de línea. */
    private function certificateToBase64(Certificate $certificate): string
    {
        $pem = $certificate->certificatePem();
        $body = preg_replace('/-----(BEGIN|END) CERTIFICATE-----/', '', $pem);

        return trim(preg_replace('/\s+/', '', (string) $body));
    }
}
