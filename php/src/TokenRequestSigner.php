<?php

declare(strict_types=1);

namespace IngeInnova\DteChile;

use DOMDocument;
use DOMElement;
use RuntimeException;

/**
 * Firma la solicitud de token (`<getToken><item><Semilla>...</Semilla>
 * </item></getToken>`) que el SII exige para autenticar una sesión, a
 * partir de la semilla que entrega SiiClient::getSeed(). Es una firma
 * XMLDSig "enveloped" (referencia el documento completo, no un nodo por
 * ID) — distinta de DteSigner, que firma el <Documento> del DTE.
 */
final class TokenRequestSigner
{
    private const XMLDSIG_NAMESPACE = 'http://www.w3.org/2000/09/xmldsig#';
    private const ENVELOPED_TRANSFORM = 'http://www.w3.org/2000/09/xmldsig#enveloped-signature';
    private const C14N_ALGORITHM = 'http://www.w3.org/TR/2001/REC-xml-c14n-20010315';
    private const SIGNATURE_METHOD = 'http://www.w3.org/2000/09/xmldsig#rsa-sha1';
    private const DIGEST_METHOD = 'http://www.w3.org/2000/09/xmldsig#sha1';

    /** @throws RuntimeException Si la firma con OpenSSL falla. */
    public function sign(string $semilla, Certificate $certificate): DOMDocument
    {
        // Sin encoding en el prólogo — así lo muestra el manual oficial del
        // SII (OI2007_AUTAUTOM_MDE, figura 1.4).
        $doc = new DOMDocument('1.0');

        $getToken = $doc->createElement('getToken');
        $doc->appendChild($getToken);

        $item = $doc->createElement('item');
        $item->appendChild(Xml::el($doc, 'Semilla', $semilla));
        $getToken->appendChild($item);

        // El digest se calcula ANTES de agregar <Signature> — es sobre el
        // documento tal como queda sin ella (la transformada
        // "enveloped-signature" existe justamente para dejar esto explícito
        // ante cualquier verificador que sí reciba el documento completo).
        $digestValue = base64_encode(sha1($doc->C14N(), true));

        $this->appendSignature($doc, $getToken, $digestValue, $certificate);

        return $doc;
    }

    private function appendSignature(DOMDocument $doc, DOMElement $root, string $digestValue, Certificate $certificate): void
    {
        $ns = self::XMLDSIG_NAMESPACE;

        // Igual que en DteSigner: <Signature> se adjunta al árbol ANTES de
        // canonicalizar <SignedInfo>, para que el C14N (no exclusivo) que se
        // firma incluya el mismo contexto de namespaces que va a ver
        // cualquiera que reciba el documento final.
        $signature = $doc->createElementNS($ns, 'Signature');
        $root->appendChild($signature);

        $signedInfo = $doc->createElementNS($ns, 'SignedInfo');
        $signature->appendChild($signedInfo);

        $canonicalizationMethod = $doc->createElementNS($ns, 'CanonicalizationMethod');
        $canonicalizationMethod->setAttribute('Algorithm', self::C14N_ALGORITHM);
        $signedInfo->appendChild($canonicalizationMethod);

        $signatureMethod = $doc->createElementNS($ns, 'SignatureMethod');
        $signatureMethod->setAttribute('Algorithm', self::SIGNATURE_METHOD);
        $signedInfo->appendChild($signatureMethod);

        $reference = $doc->createElementNS($ns, 'Reference');
        $reference->setAttribute('URI', ''); // documento completo, no un nodo por ID

        // Un solo Transform (enveloped-signature) — el manual oficial del SII
        // (OI2007_AUTAUTOM_MDE, figura 1.4) NO agrega un segundo Transform de
        // C14N acá, a diferencia de lo que XMLDSig permitiría en general.
        $transforms = $doc->createElementNS($ns, 'Transforms');
        $envelopedTransform = $doc->createElementNS($ns, 'Transform');
        $envelopedTransform->setAttribute('Algorithm', self::ENVELOPED_TRANSFORM);
        $transforms->appendChild($envelopedTransform);
        $reference->appendChild($transforms);

        $digestMethod = $doc->createElementNS($ns, 'DigestMethod');
        $digestMethod->setAttribute('Algorithm', self::DIGEST_METHOD);
        $reference->appendChild($digestMethod);

        $reference->appendChild($doc->createElementNS($ns, 'DigestValue', $digestValue));
        $signedInfo->appendChild($reference);

        $signatureValueRaw = '';
        $signed = openssl_sign($signedInfo->C14N(), $signatureValueRaw, $certificate->privateKey(), OPENSSL_ALGO_SHA1);
        if (!$signed) {
            throw new RuntimeException('No fue posible firmar la solicitud de token con la llave privada del certificado.');
        }
        $signature->appendChild($doc->createElementNS($ns, 'SignatureValue', base64_encode($signatureValueRaw)));

        $signature->appendChild($this->buildKeyInfo($doc, $certificate));
    }

    private function buildKeyInfo(DOMDocument $doc, Certificate $certificate): DOMElement
    {
        $ns = self::XMLDSIG_NAMESPACE;

        $details = openssl_pkey_get_details($certificate->privateKey());
        if ($details === false || !isset($details['rsa']['n'], $details['rsa']['e'])) {
            throw new RuntimeException('No fue posible leer el módulo/exponente RSA del certificado.');
        }

        $keyInfo = $doc->createElementNS($ns, 'KeyInfo');

        // El SII exige el módulo/exponente además del certificado X509 — sin
        // este nodo rechaza la solicitud con un error de "Certificate no
        // existe" aunque el X509Certificate sí esté presente.
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

    private function certificateToBase64(Certificate $certificate): string
    {
        $pem = $certificate->certificatePem();
        $body = preg_replace('/-----(BEGIN|END) CERTIFICATE-----/', '', $pem);

        return trim(preg_replace('/\s+/', '', (string) $body));
    }
}
