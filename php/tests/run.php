<?php

declare(strict_types=1);

/**
 * Pruebas automáticas de dte-chile, sin dependencias: php tests/run.php
 * Todo corre sin red y sin credenciales reales (certificado y CAF de prueba generados al vuelo).
 * Verifica las firmas con OpenSSL de forma independiente al código de la librería.
 */

namespace IngeInnova\DteChile\Tests;

use DOMDocument;
use DOMXPath;
use IngeInnova\DteChile\Caf;
use IngeInnova\DteChile\Certificate;
use IngeInnova\DteChile\DteBuilder;
use IngeInnova\DteChile\DteSigner;
use IngeInnova\DteChile\EnvioDteBuilder;
use IngeInnova\DteChile\Rut;
use IngeInnova\DteChile\TedSigner;
use IngeInnova\DteChile\TokenRequestSigner;

spl_autoload_register(static function (string $class): void {
    foreach (['IngeInnova\\DteChile\\Tests\\' => __DIR__ . '/', 'IngeInnova\\DteChile\\' => __DIR__ . '/../src/'] as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }

            return;
        }
    }
});
require_once __DIR__ . '/Fixtures.php';

// ---------------------------------------------------------------- mini framework
$resultados = [];
function prueba(string $nombre, callable $fn): void
{
    global $resultados;
    try {
        $fn();
        $resultados[] = [$nombre, true, ''];
    } catch (\Throwable $e) {
        $resultados[] = [$nombre, false, $e->getMessage()];
    }
}
function afirma(bool $condicion, string $mensaje): void
{
    if (!$condicion) {
        throw new \RuntimeException($mensaje);
    }
}
function igual(mixed $esperado, mixed $real, string $que): void
{
    if ($esperado !== $real) {
        throw new \RuntimeException("$que: esperado " . var_export($esperado, true) . ', obtenido ' . var_export($real, true));
    }
}
function lanza(callable $fn, string $fragmento): void
{
    try {
        $fn();
    } catch (\Throwable $e) {
        afirma(str_contains($e->getMessage(), $fragmento), "El error no menciona «{$fragmento}»: " . $e->getMessage());

        return;
    }
    throw new \RuntimeException("Debía lanzar un error con «{$fragmento}» y no lo hizo.");
}

// ---------------------------------------------------------------- verificadores independientes
/** Bytes finales del documento, tal como se transmitirían (ISO-8859-1). */
function bytes(DOMDocument $d): string
{
    return (string) $d->saveXML();
}
/** Verifica con OpenSSL cada <Signature> XMLDSig del documento: resumen de la referencia y firma del SignedInfo con el certificado dado. */
function verificaXmlDsig(string $xmlBytes, string $certPem): int
{
    $doc = new DOMDocument();
    afirma($doc->loadXML($xmlBytes), 'El XML final no se puede volver a leer.');
    $xp = new DOMXPath($doc);
    $xp->registerNamespace('ds', 'http://www.w3.org/2000/09/xmldsig#');
    $firmas = $xp->query('//ds:Signature');
    afirma($firmas->length > 0, 'No hay ninguna firma XMLDSig.');
    $pub = openssl_pkey_get_public($certPem);
    foreach ($firmas as $sig) {
        $uri = $xp->evaluate('string(ds:SignedInfo/ds:Reference/@URI)', $sig);
        if ($uri === '') { // «enveloped» sobre todo el documento
            $copia = new DOMDocument();
            $copia->loadXML($xmlBytes);
            $cx = new DOMXPath($copia);
            $cx->registerNamespace('ds', 'http://www.w3.org/2000/09/xmldsig#');
            foreach (iterator_to_array($cx->query('//ds:Signature')) as $s) {
                $s->parentNode->removeChild($s);
            }
            $canon = $copia->C14N(false, false);
        } else {
            $objetivo = $xp->query('//*[@ID="' . ltrim($uri, '#') . '"]')->item(0);
            afirma($objetivo !== null, "La referencia $uri no apunta a ningún elemento.");
            $canon = $objetivo->C14N(false, false);
        }
        $digest = $xp->evaluate('string(ds:SignedInfo/ds:Reference/ds:DigestValue)', $sig);
        igual(base64_encode(sha1($canon, true)), trim($digest), "Resumen (DigestValue) de la referencia $uri");
        $signedInfo = $xp->query('ds:SignedInfo', $sig)->item(0)->C14N(false, false);
        $valor = base64_decode(trim($xp->evaluate('string(ds:SignatureValue)', $sig)), true);
        igual(1, openssl_verify($signedInfo, $valor, $pub, OPENSSL_ALGO_SHA1), "Firma del SignedInfo de $uri");
    }

    return $firmas->length;
}
/** Verifica el timbre (TED): firma del <DD> sin espacios entre etiquetas, con la llave pública del CAF, sobre los bytes finales del documento. */
function verificaTed(string $xmlBytes, string $cafPublicPem): void
{
    afirma(preg_match('/<DD>.*<\/DD>/s', $xmlBytes, $m) === 1, 'No se encontró <DD> en el documento final.');
    afirma(preg_match('/<FRMT[^>]*>([^<]+)<\/FRMT>/', $xmlBytes, $f) === 1, 'No se encontró <FRMT>.');
    $dd = preg_replace('/>\s+</', '><', $m[0]);
    igual(1, openssl_verify($dd, base64_decode($f[1]), openssl_pkey_get_public($cafPublicPem), OPENSSL_ALGO_SHA1), 'Firma del timbre (TED)');
}
/** Valida el XML contra un esquema oficial del SII (carpeta schemas/ del repositorio); si no cumple, el error lista los primeros problemas. */
function validaXsd(string $xmlBytes, string $xsd): void
{
    $doc = new DOMDocument();
    afirma($doc->loadXML($xmlBytes), 'El XML no se puede leer.');
    $usar = libxml_use_internal_errors(true);
    libxml_clear_errors();
    // Algunos esquemas del SII traen límites de 34 dígitos (999...9) que libxml2 no acepta; se validan con una copia con el límite reducido.
    $origen = dirname(__DIR__ . '/../../schemas/' . $xsd);
    $tmp = sys_get_temp_dir() . '/xsd_' . bin2hex(random_bytes(4));
    mkdir($tmp);
    foreach (glob($origen . '/*.xsd') as $f) {
        file_put_contents($tmp . '/' . basename($f), preg_replace('/9{19,}(\.9+)?/', '999999999999999', (string) file_get_contents($f)));
    }
    $ok = $doc->schemaValidate($tmp . '/' . basename($xsd));
    array_map('unlink', glob($tmp . '/*') ?: []);
    rmdir($tmp);
    $errores = array_map(static fn (\LibXMLError $e): string => 'línea ' . $e->line . ': ' . trim($e->message), array_slice(libxml_get_errors(), 0, 4));
    libxml_clear_errors();
    libxml_use_internal_errors($usar);
    afirma($ok, "No cumple el esquema oficial $xsd: " . implode(' | ', $errores));
}
/** DTE completo: construye, timbra y firma. */
function dteFirmado(array $datos, int $tipo = 33, ?array $caf = null, ?Certificate $cert = null): array
{
    $caf ??= Fixtures::caf($tipo, 1, 100);
    $cert ??= Fixtures::certificateObject();
    $b = new DteBuilder();
    $doc = $b->build($tipo, $datos);
    $datosTed = $datos + ['montoTotal' => $b->montoTotal($doc)];
    $b->attachTed($doc, (new TedSigner())->sign($caf['caf'], $tipo, $datosTed));

    return [(new DteSigner())->sign($doc, $cert), $caf, $cert];
}

// ================================================================= pruebas: RUT
prueba('Rut: acepta dígitos verificadores correctos', function (): void {
    foreach (['76.123.456-0', '77111222-6', '11.111.111-1', '12345678-5', '1-9'] as $r) {
        afirma(Rut::isValid($r), "$r debería ser válido");
    }
});
prueba('Rut: rechaza dígitos verificadores y formatos incorrectos', function (): void {
    foreach (['76.123.456-1', '12345678-0', '', 'abc', '123456789-0', '12345678'] as $r) {
        afirma(!Rut::isValid($r), "«{$r}» debería ser inválido");
    }
});
prueba('Rut: normaliza puntos, espacios y K minúscula', function (): void {
    igual('76123456-0', Rut::toDteFormat('76.123.456-0'), 'puntos');
    igual('12345678-K', Rut::toDteFormat(' 12.345.678-k '), 'K minúscula y espacios');
});

// ================================================================= pruebas: Certificado y CAF
prueba('Certificado: abre un .pfx y lee el RUT del titular', function (): void {
    $c = Fixtures::certificateObject();
    igual('11111111-1', $c->subjectRut(), 'RUT del certificado');
    afirma($c->notAfter() > new \DateTimeImmutable('now'), 'El certificado de prueba debería estar vigente.');
});
prueba('Certificado: contraseña equivocada o archivo corrupto dan un error claro', function (): void {
    lanza(fn () => Certificate::fromPkcs12(Fixtures::certificate()['pfx'], 'otra-clave'), 'contraseña');
    lanza(fn () => Certificate::fromPkcs12('no soy un pfx', 'x'), 'contraseña');
});
prueba('CAF: lee tipo, rango y emisor; reconoce folios dentro y fuera del rango', function (): void {
    $c = Fixtures::caf(33, 5, 10)['caf'];
    igual(33, $c->documentType(), 'tipo');
    igual([5, 10], [$c->folioFrom(), $c->folioTo()], 'rango');
    afirma($c->containsFolio(5) && $c->containsFolio(10) && !$c->containsFolio(4) && !$c->containsFolio(11), 'límites del rango');
    igual('76123456-0', $c->issuerRut(), 'RUT emisor');
});
prueba('CAF: rechaza archivos que no son un CAF', function (): void {
    lanza(fn () => Caf::fromXml('<hola/>'), 'CAF');
    lanza(fn () => Caf::fromXml('no es xml'), 'XML');
});

// ================================================================= pruebas: montos
prueba('Montos: neto, IVA 19 % y total de una factura simple', function (): void {
    $b = new DteBuilder();
    $doc = $b->build(33, Fixtures::datosFactura(1, ['items' => [['nombre' => 'A', 'cantidad' => 10, 'precioUnitario' => 25000]]]));
    $v = fn (string $t) => (int) $doc->getElementsByTagName($t)->item(0)->textContent;
    igual(250000, $v('MntNeto'), 'neto');
    igual(47500, $v('IVA'), 'IVA');
    igual(297500, $v('MntTotal'), 'total');
});
prueba('Montos: redondeo del IVA y de cantidades decimales', function (): void {
    $b = new DteBuilder();
    // 3 × 1.990 = 5.970 → IVA 1.134,3 → 1.134 → total 7.104
    $doc = $b->build(33, Fixtures::datosFactura(1, ['items' => [['nombre' => 'A', 'cantidad' => 3, 'precioUnitario' => 1990]]]));
    igual(5970, (int) $doc->getElementsByTagName('MntNeto')->item(0)->textContent, 'neto');
    igual(1134, (int) $doc->getElementsByTagName('IVA')->item(0)->textContent, 'IVA');
    igual(7104, $b->montoTotal($doc), 'total');
    // 2,5 × 999 = 2.497,5 → se redondea a 2.498 (mitad hacia arriba)
    $doc = $b->build(33, Fixtures::datosFactura(1, ['items' => [['nombre' => 'A', 'cantidad' => 2.5, 'precioUnitario' => 999]]]));
    igual(2498, (int) $doc->getElementsByTagName('MntNeto')->item(0)->textContent, 'neto con cantidad decimal');
});
prueba('Montos: varias líneas suman bien y numeran sus detalles', function (): void {
    $b = new DteBuilder();
    $doc = $b->build(33, Fixtures::datosFactura(1, ['items' => [['nombre' => 'A', 'cantidad' => 1, 'precioUnitario' => 1000], ['nombre' => 'B', 'cantidad' => 2, 'precioUnitario' => 500], ['nombre' => 'C', 'cantidad' => 4, 'precioUnitario' => 250]]]));
    igual(3000, (int) $doc->getElementsByTagName('MntNeto')->item(0)->textContent, 'neto');
    igual(3, $doc->getElementsByTagName('Detalle')->length, 'cantidad de líneas');
    igual('3', $doc->getElementsByTagName('NroLinDet')->item(2)->textContent, 'numeración');
});

// ================================================================= pruebas: validación de datos
prueba('Datos: rechaza RUT inválido y campos obligatorios ausentes', function (): void {
    $b = new DteBuilder();
    lanza(fn () => $b->build(33, Fixtures::datosFactura(1, ['receptor' => ['rut' => '77.111.222-0']])), 'RUT');
    $sinItems = Fixtures::datosFactura();
    $sinItems['items'] = [];
    lanza(fn () => $b->build(33, $sinItems), 'tem');
    $sinFolio = Fixtures::datosFactura();
    unset($sinFolio['folio']);
    lanza(fn () => $b->build(33, $sinFolio), 'folio');
});

prueba('Datos: folio no positivo y fecha inválida se rechazan con un error claro', function (): void {
    $b = new DteBuilder();
    lanza(fn () => $b->build(33, Fixtures::datosFactura(0)), 'folio');
    lanza(fn () => $b->build(33, Fixtures::datosFactura(1, ['fechaEmision' => '08/09/2026'])), 'fecha');
    lanza(fn () => $b->build(33, Fixtures::datosFactura(1, ['fechaEmision' => '2026-02-30'])), 'fecha');
});
prueba('Texto: caracteres que el SII no admite se normalizan o se rechazan, nunca se cambian en silencio', function (): void {
    $b = new DteBuilder();
    $doc = $b->build(33, Fixtures::datosFactura(1, ['items' => [['nombre' => 'Diseño – “premium” … año', 'cantidad' => 1, 'precioUnitario' => 1000]]]));
    igual('Diseño - "premium" ... año', $doc->getElementsByTagName('NmbItem')->item(0)->textContent, 'signos tipográficos normalizados');
    lanza(fn () => $b->build(33, Fixtures::datosFactura(1, ['items' => [['nombre' => 'Torta 🎂', 'cantidad' => 1, 'precioUnitario' => 1000]]])), 'no admite');
    lanza(fn () => $b->build(33, Fixtures::datosFactura(1, ['receptor' => ['razonSocial' => "Cliente\x07"]])), 'control');
});

// ================================================================= pruebas: caracteres especiales
prueba('Texto: razón social con & y comillas produce un XML válido', function (): void {
    $b = new DteBuilder();
    $doc = $b->build(33, Fixtures::datosFactura(1, ['receptor' => ['razonSocial' => 'Muebles & Cía "El Pino" <Ltda>']]));
    $xml = bytes($doc);
    $re = new DOMDocument();
    afirma(@$re->loadXML($xml), 'El XML con & en la razón social no se puede volver a leer.');
    igual('Muebles & Cía "El Pino" <Ltda>', $re->getElementsByTagName('RznSocRecep')->item(0)->textContent, 'razón social conservada');
});
prueba('Texto: tildes y ñ en el documento final se codifican en ISO-8859-1', function (): void {
    $b = new DteBuilder();
    $xml = bytes($b->build(33, Fixtures::datosFactura(1, ['items' => [['nombre' => 'Ñandú año 2026 – café', 'cantidad' => 1, 'precioUnitario' => 1000]]])));
    afirma(str_contains($xml, 'encoding="ISO-8859-1"'), 'Falta la declaración ISO-8859-1.');
    afirma(str_contains($xml, "\xD1and\xFA a\xF1o"), 'Los caracteres Ñ, ú y ñ deberían salir como bytes ISO-8859-1.');
});

// ================================================================= pruebas: timbre (TED)
prueba('TED: la firma se verifica con la llave pública del CAF (texto simple)', function (): void {
    [$firmado, $caf] = dteFirmado(Fixtures::datosFactura());
    verificaTed(bytes($firmado), $caf['publicPem']);
});
prueba('TED: la firma se verifica aunque el CAF venga con indentación propia o sin ella', function (): void {
    foreach ([true, false] as $indent) {
        [$firmado, $caf] = dteFirmado(Fixtures::datosFactura(), 33, Fixtures::caf(33, 1, 100, $indent));
        verificaTed(bytes($firmado), $caf['publicPem']);
    }
});
prueba('TED: la firma se verifica con tildes y ñ en el primer ítem y en el receptor', function (): void {
    [$firmado, $caf] = dteFirmado(Fixtures::datosFactura(1, ['receptor' => ['razonSocial' => 'Ñuñoa Ñandú Ltda'], 'items' => [['nombre' => 'Consultoría técnica año', 'cantidad' => 1, 'precioUnitario' => 1000]]]));
    verificaTed(bytes($firmado), $caf['publicPem']);
});
prueba('TED: la firma se verifica con & en la razón social del receptor', function (): void {
    [$firmado, $caf] = dteFirmado(Fixtures::datosFactura(1, ['receptor' => ['razonSocial' => 'Muebles & Cía']]));
    verificaTed(bytes($firmado), $caf['publicPem']);
});
prueba('TED: folio fuera del rango o CAF de otro tipo se rechazan antes de firmar', function (): void {
    lanza(fn () => dteFirmado(Fixtures::datosFactura(101), 33, Fixtures::caf(33, 1, 100)), 'folio');
    lanza(fn () => dteFirmado(Fixtures::datosFactura(1), 33, Fixtures::caf(34, 1, 100)), 'tipo de documento');
});

// ================================================================= pruebas: firma XMLDSig
prueba('Firma del DTE: resumen y firma se verifican con OpenSSL', function (): void {
    [$firmado, , $cert] = dteFirmado(Fixtures::datosFactura());
    igual(1, verificaXmlDsig(bytes($firmado), $cert->certificatePem()), 'cantidad de firmas');
});
prueba('Firma del DTE: sigue válida con tildes, ñ y & en el contenido', function (): void {
    [$firmado, , $cert] = dteFirmado(Fixtures::datosFactura(1, ['receptor' => ['razonSocial' => 'Peña & Cía Ñandú'], 'items' => [['nombre' => 'Diseño año – café', 'cantidad' => 2, 'precioUnitario' => 1500]]]));
    verificaXmlDsig(bytes($firmado), $cert->certificatePem());
});
prueba('Firma del DTE: alterar un monto después de firmar invalida la firma', function (): void {
    [$firmado, , $cert] = dteFirmado(Fixtures::datosFactura());
    $alterado = str_replace('<MntTotal>297500</MntTotal>', '<MntTotal>100</MntTotal>', bytes($firmado));
    afirma($alterado !== bytes($firmado), 'La prueba no pudo alterar el monto.');
    lanza(fn () => verificaXmlDsig($alterado, $cert->certificatePem()), 'Resumen');
});
prueba('Firma del DTE: otro certificado no verifica la firma', function (): void {
    [$firmado] = dteFirmado(Fixtures::datosFactura());
    lanza(fn () => verificaXmlDsig(bytes($firmado), Fixtures::certificate()['certPem']), 'Firma');
});

// ================================================================= pruebas: EnvioDTE
prueba('EnvioDTE: carátula con subtotales y las dos firmas (DTE y SetDTE) válidas', function (): void {
    $cert = Fixtures::certificateObject();
    $caf = Fixtures::caf(33, 1, 100);
    [$d1] = dteFirmado(Fixtures::datosFactura(1), 33, $caf, $cert);
    [$d2] = dteFirmado(Fixtures::datosFactura(2), 33, $caf, $cert);
    $envio = (new EnvioDteBuilder())->build([$d1, $d2], ['rutEmisor' => '76.123.456-0', 'rutEnvia' => '11.111.111-1', 'fechaResolucion' => '2026-01-01', 'numeroResolucion' => 0], $cert);
    $xml = bytes($envio);
    igual(3, verificaXmlDsig($xml, $cert->certificatePem()), 'firmas (2 DTE + 1 SetDTE)');
    $doc = new DOMDocument();
    $doc->loadXML($xml);
    igual('2', $doc->getElementsByTagName('NroDTE')->item(0)->textContent, 'NroDTE');
    igual('60803000-K', $doc->getElementsByTagName('RutReceptor')->item(0)->textContent, 'receptor fijo del SII');
    igual('76123456-0', $doc->getElementsByTagName('RutEmisor')->item(0)->textContent, 'RUT emisor');
});

// ================================================================= pruebas: token del SII
prueba('Solicitud de token: firma «enveloped» válida con un solo Transform', function (): void {
    $cert = Fixtures::certificateObject();
    $doc = (new TokenRequestSigner())->sign('123456789012', $cert);
    $xml = bytes($doc);
    verificaXmlDsig($xml, $cert->certificatePem());
    igual(1, substr_count($xml, '<Transform '), 'cantidad de Transform (el SII rechaza más de uno)');
    afirma(str_contains($xml, '<Semilla>123456789012</Semilla>'), 'Falta la semilla.');
});

// ================================================================= pruebas: montos avanzados (cálculo a mano + esquema oficial)
function totales(array $datos, int $tipo = 33): array
{
    [$firmado] = dteFirmado($datos, $tipo);
    validaXsd(bytes($firmado), 'dte/DTE_v10.xsd');
    $d = new DOMDocument();
    $d->loadXML(bytes($firmado));
    $t = [];
    foreach ($d->getElementsByTagName('Totales')->item(0)->childNodes as $n) {
        $t[$n->nodeName] = $n->nodeName === 'ImptoReten' ? ($t[$n->nodeName] ?? []) + [count($t[$n->nodeName] ?? []) => $n->textContent] : $n->textContent;
    }

    return $t;
}
prueba('Montos: descuento y recargo por línea (porcentaje y monto) se aplican y se informan', function (): void {
    $b = new DteBuilder();
    $doc = $b->build(33, Fixtures::datosFactura(1, ['items' => [
        ['nombre' => 'A', 'cantidad' => 10, 'precioUnitario' => 1000, 'descuentoPct' => 10],
        ['nombre' => 'B', 'cantidad' => 1, 'precioUnitario' => 2000, 'recargoMonto' => 300],
    ]]));
    $m = fn (int $i) => (int) $doc->getElementsByTagName('MontoItem')->item($i)->textContent;
    igual(9000, $m(0), 'línea con 10 % de descuento');
    igual(2300, $m(1), 'línea con recargo de 300');
    igual('1000', $doc->getElementsByTagName('DescuentoMonto')->item(0)->textContent, 'el descuento en pesos queda informado');
    igual(11300, (int) $doc->getElementsByTagName('MntNeto')->item(0)->textContent, 'neto');
    igual(2147, (int) $doc->getElementsByTagName('IVA')->item(0)->textContent, 'IVA (11.300 × 19 % = 2.147)');
});
prueba('Montos: ítems afectos y exentos mezclados con un descuento global del 10 % a los afectos', function (): void {
    $t = totales(Fixtures::datosFactura(1, [
        'items' => [['nombre' => 'A', 'cantidad' => 10, 'precioUnitario' => 1000], ['nombre' => 'B', 'cantidad' => 1, 'precioUnitario' => 500, 'exento' => true]],
        'descuentosRecargos' => [['tipo' => 'D', 'valorTipo' => '%', 'valor' => 10, 'glosa' => 'Pago contado']],
    ]));
    igual(['9000', '500', '19', '1710', '11210'], [$t['MntNeto'], $t['MntExe'], $t['TasaIVA'], $t['IVA'], $t['MntTotal']], 'neto, exento, tasa, IVA y total');
});
prueba('Montos: descuento global en pesos al grupo exento (IndExeDR = 1)', function (): void {
    $t = totales(Fixtures::datosFactura(1, [
        'items' => [['nombre' => 'A', 'cantidad' => 1, 'precioUnitario' => 10000], ['nombre' => 'B', 'cantidad' => 1, 'precioUnitario' => 5000, 'exento' => true]],
        'descuentosRecargos' => [['tipo' => 'D', 'valorTipo' => '$', 'valor' => 1000, 'exento' => 1]],
    ]));
    igual(['10000', '4000', '1900', '15900'], [$t['MntNeto'], $t['MntExe'], $t['IVA'], $t['MntTotal']], 'el descuento afecta solo a lo exento');
});
prueba('Montos: impuesto adicional (código 24, 31,5 %) suma al total con su tasa explícita', function (): void {
    $t = totales(Fixtures::datosFactura(1, [
        'items' => [['nombre' => 'Licor', 'cantidad' => 1, 'precioUnitario' => 10000, 'impuestoAdicional' => '24']],
        'impuestos' => ['24' => 31.5],
    ]));
    igual(['10000', '1900', '15050'], [$t['MntNeto'], $t['IVA'], $t['MntTotal']], '10.000 + IVA 1.900 + impuesto 3.150');
    afirma(str_contains($t['ImptoReten'][0], '3150'), 'Falta el monto del impuesto adicional (3.150).');
});
prueba('Montos: retención de IVA (código 15) resta del total y exige indicar su tasa', function (): void {
    $datos = Fixtures::datosFactura(1, ['items' => [['nombre' => 'Producto', 'cantidad' => 1, 'precioUnitario' => 10000, 'impuestoAdicional' => '15']], 'impuestos' => ['15' => 19]]);
    $t = totales($datos, 46);
    igual(['10000', '1900', '10000'], [$t['MntNeto'], $t['IVA'], $t['MntTotal']], 'neto + IVA − retención = 10.000');
    unset($datos['impuestos']);
    lanza(fn () => (new DteBuilder())->build(46, $datos), 'necesita su tasa');
});
prueba('Montos: ítem no facturable (IndExe 2) va aparte y no suma al total', function (): void {
    $t = totales(Fixtures::datosFactura(1, ['items' => [['nombre' => 'A', 'cantidad' => 1, 'precioUnitario' => 1000], ['nombre' => 'Envase', 'cantidad' => 1, 'precioUnitario' => 200, 'exento' => 2]]]));
    igual(['1000', '190', '1190', '200'], [$t['MntNeto'], $t['IVA'], $t['MntTotal'], $t['MontoNF']], 'el no facturable queda en MontoNF');
});
prueba('Montos: un bloque «totales» explícito reemplaza al cálculo automático', function (): void {
    $t = totales(Fixtures::datosFactura(1, ['totales' => ['MntNeto' => 1000, 'TasaIVA' => 19, 'IVA' => 190, 'MntTotal' => 1190]]));
    igual(['1000', '190', '1190'], [$t['MntNeto'], $t['IVA'], $t['MntTotal']], 'totales explícitos');
});
prueba('Esquema: validaXsd detecta de verdad un documento inválido (la prueba no es vacía)', function (): void {
    [$firmado] = dteFirmado(Fixtures::datosFactura(1));
    $roto = str_replace('<TipoDTE>33</TipoDTE>', '<TipoDTE>99</TipoDTE>', bytes($firmado));
    lanza(fn () => validaXsd($roto, 'dte/DTE_v10.xsd'), 'No cumple el esquema');
});

// ================================================================= pruebas: esquema oficial del SII
prueba('Esquema SII: una factura (33) firmada y su sobre EnvioDTE cumplen los esquemas oficiales', function (): void {
    $cert = Fixtures::certificateObject();
    [$firmado, $caf] = dteFirmado(Fixtures::datosFactura(1), 33, null, $cert);
    validaXsd(bytes($firmado), 'dte/DTE_v10.xsd');
    $envio = (new EnvioDteBuilder())->build([$firmado], ['rutEmisor' => '76.123.456-0', 'rutEnvia' => '11.111.111-1', 'fechaResolucion' => '2026-01-01', 'numeroResolucion' => 0], $cert);
    validaXsd(bytes($envio), 'dte/EnvioDTE_v10.xsd');
});
foreach ([33 => 'factura', 34 => 'factura exenta', 46 => 'factura de compra', 52 => 'guía de despacho'] as $tipo => $nombre) {
    prueba("Tipo $tipo ($nombre): se construye, se timbra, se firma y cumple el esquema oficial", function () use ($tipo): void {
        $datos = Fixtures::datosFactura(1);
        if ($tipo === 34) {
            $datos['items'] = [['nombre' => 'Servicio exento', 'cantidad' => 2, 'precioUnitario' => 5000, 'exento' => true]];
        }
        if ($tipo === 52) {
            $datos['indicadorTraslado'] = 1;
        }
        [$firmado, $caf, $cert] = dteFirmado($datos, $tipo);
        verificaTed(bytes($firmado), $caf['publicPem']);
        verificaXmlDsig(bytes($firmado), $cert->certificatePem());
        validaXsd(bytes($firmado), 'dte/DTE_v10.xsd');
        $d = new DOMDocument();
        $d->loadXML(bytes($firmado));
        igual((string) $tipo, $d->getElementsByTagName('TipoDTE')->item(0)->textContent, 'TipoDTE');
    });
}
foreach ([56 => 'nota de débito', 61 => 'nota de crédito'] as $tipo => $nombre) {
    prueba("Tipo $tipo ($nombre): con referencia al documento que corrige, cumple el esquema oficial", function () use ($tipo): void {
        $datos = Fixtures::datosFactura(1) + ['referencias' => [['tipoDocumento' => 33, 'folio' => 10, 'fecha' => '2026-09-01', 'codigo' => 1, 'razon' => 'Anula documento']]];
        [$firmado, $caf, $cert] = dteFirmado($datos, $tipo);
        $xml = bytes($firmado);
        verificaTed($xml, $caf['publicPem']);
        verificaXmlDsig($xml, $cert->certificatePem());
        validaXsd($xml, 'dte/DTE_v10.xsd');
        afirma(str_contains($xml, '<Referencia>'), 'Falta el bloque <Referencia>.');
    });
}
prueba('Tipo 34: en la factura exenta todo va a «Monto exento», sin IVA y con IndExe = 1 en cada línea', function (): void {
    [$firmado, $caf, $cert] = dteFirmado(Fixtures::datosFactura(1, ['items' => [['nombre' => 'Asesoría', 'cantidad' => 3, 'precioUnitario' => 1000]]]), 34);
    $xml = bytes($firmado);
    verificaTed($xml, $caf['publicPem']);
    verificaXmlDsig($xml, $cert->certificatePem());
    validaXsd($xml, 'dte/DTE_v10.xsd');
    afirma(str_contains($xml, '<MntExe>3000</MntExe>') && !str_contains($xml, '<IVA>') && str_contains($xml, '<IndExe>1</IndExe>'), 'La factura exenta debe llevar MntExe 3000, sin IVA y con IndExe 1.');
});
foreach ([110 => 'factura de exportación', 111 => 'nota de débito de exportación', 112 => 'nota de crédito de exportación'] as $tipo => $nombre) {
    prueba("Tipo $tipo ($nombre): monto en moneda extranjera con decimales, cumple el esquema oficial", function () use ($tipo): void {
        $datos = Fixtures::datosFactura(1, ['moneda' => 'DOLAR USA', 'items' => [['nombre' => 'Vino', 'cantidad' => 100, 'precioUnitario' => 12.5]]]);
        if ($tipo !== 110) {
            $datos['referencias'] = [['tipoDocumento' => 110, 'folio' => 5, 'fecha' => '2026-09-01', 'codigo' => 1, 'razon' => 'Corrige']];
        }
        [$firmado, $caf, $cert] = dteFirmado($datos, $tipo);
        $xml = bytes($firmado);
        verificaTed($xml, $caf['publicPem']);
        verificaXmlDsig($xml, $cert->certificatePem());
        validaXsd($xml, 'dte/DTE_v10.xsd');
        afirma(str_contains($xml, '<TpoMoneda>DOLAR USA</TpoMoneda>') && str_contains($xml, '<MntTotal>1250</MntTotal>') && !str_contains($xml, '<IVA>'), 'Faltan la moneda o el total 1250 sin IVA.');
    });
}
prueba('Tipo 43 (liquidación-factura): exige el bloque «totales» y con él cumple el esquema oficial', function (): void {
    $items = [['tipoDocumentoLiquidado' => '30', 'nombre' => 'Venta por cuenta de terceros', 'monto' => 10000]];
    lanza(fn () => (new DteBuilder())->build(43, Fixtures::datosFactura(1, ['items' => $items])), 'totales');
    [$firmado, $caf, $cert] = dteFirmado(Fixtures::datosFactura(1, ['items' => $items, 'totales' => ['MntTotal' => 10000]]), 43);
    verificaTed(bytes($firmado), $caf['publicPem']);
    verificaXmlDsig(bytes($firmado), $cert->certificatePem());
    validaXsd(bytes($firmado), 'dte/DTE_v10.xsd');
});
prueba('Sobre: documentos de exportación y facturas viajan juntos en un EnvioDTE válido', function (): void {
    $cert = Fixtures::certificateObject();
    [$fa] = dteFirmado(Fixtures::datosFactura(1), 33, null, $cert);
    [$ex] = dteFirmado(Fixtures::datosFactura(1, ['moneda' => 'DOLAR USA', 'items' => [['nombre' => 'Vino', 'cantidad' => 1, 'precioUnitario' => 10]]]), 110, null, $cert);
    $envio = (new EnvioDteBuilder())->build([$fa, $ex], ['rutEmisor' => '76.123.456-0', 'rutEnvia' => '11.111.111-1', 'fechaResolucion' => '2026-01-01', 'numeroResolucion' => 0], $cert);
    verificaXmlDsig(bytes($envio), $cert->certificatePem());
    validaXsd(bytes($envio), 'dte/EnvioDTE_v10.xsd');
});
prueba('Boletas (39 y 41): DteBuilder las rechaza y apunta a BoletaBuilder', function (): void {
    foreach ([39, 41] as $tipo) {
        lanza(fn () => (new DteBuilder())->build($tipo, Fixtures::datosFactura()), 'BoletaBuilder');
    }
});
prueba('Esquema: un campo mal escrito o fuera de rango se rechaza con la ruta del campo', function (): void {
    $b = new DteBuilder();
    lanza(fn () => $b->build(33, Fixtures::datosFactura(1, ['emisor' => ['giroo' => 'x']])), 'giroo');
    lanza(fn () => $b->build(33, Fixtures::datosFactura(1, ['emisor' => ['razonSocial' => str_repeat('A', 101)]])), 'máximo es 100');
    lanza(fn () => $b->build(33, Fixtures::datosFactura(1, ['formaPago' => 9])), 'no es un valor permitido');
    lanza(fn () => $b->build(33, Fixtures::datosFactura(1, ['emisor' => ['actividadEconomica' => null]])), 'Acteco');
    lanza(fn () => $b->build(99, Fixtures::datosFactura()), 'no existe en el esquema');
});
prueba('Esquema: los campos llegan en el orden que exige el SII aunque se entreguen desordenados', function (): void {
    $datos = Fixtures::datosFactura(1);
    $datos['emisor'] = array_reverse($datos['emisor'], true);
    $datos['receptor'] = array_reverse($datos['receptor'], true);
    [$firmado] = dteFirmado($datos);
    validaXsd(bytes($firmado), 'dte/DTE_v10.xsd');
});

// ================================================================= pruebas de documentos adicionales (tests/casos/*.php)
foreach (glob(__DIR__ . '/casos/*.php') ?: [] as $archivo) {
    require $archivo;
}

// ================================================================= resultado
$fallos = 0;
foreach ($resultados as [$nombre, $ok, $detalle]) {
    echo ($ok ? "  OK   " : "  FALLA") . "  $nombre" . ($ok ? '' : "\n         -> $detalle") . "\n";
    $fallos += $ok ? 0 : 1;
}
echo "\n" . count($resultados) . ' pruebas, ' . (count($resultados) - $fallos) . " correctas, $fallos con error.\n";
exit($fallos === 0 ? 0 : 1);
