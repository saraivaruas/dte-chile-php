<?php

declare(strict_types=1);

namespace IngeInnova\DteChile\Tests;

use DOMDocument;
use IngeInnova\DteChile\SiiClient;

/** Transporte falso: registra las solicitudes y responde en orden con lo indicado. @return array{0: SiiClient, 1: \ArrayObject} */
function clienteFalso(array $respuestas, string $ambiente = SiiClient::CERTIFICACION): array
{
    $log = new \ArrayObject();
    $cola = $respuestas;
    $cliente = new SiiClient($ambiente, null, function (array $req) use ($log, &$cola): array {
        $log[] = $req;

        return array_shift($cola) ?? ['status' => 500, 'body' => 'sin respuesta preparada'];
    });

    return [$cliente, $log];
}
function soapRetorno(string $nombre, string $interior): string
{
    return '<?xml version="1.0" encoding="UTF-8"?><soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"><soapenv:Body><' . $nombre . ' xmlns="http://DefaultNamespace"><' . $nombre . 'Return>'
        . htmlspecialchars($interior, ENT_XML1) . '</' . $nombre . 'Return></' . $nombre . '></soapenv:Body></soapenv:Envelope>';
}
function respSii(string $hdr, string $body = ''): string
{
    return '<SII:RESPUESTA xmlns:SII="http://www.sii.cl/XMLSchema"><SII:RESP_HDR>' . $hdr . '</SII:RESP_HDR>' . ($body !== '' ? '<SII:RESP_BODY>' . $body . '</SII:RESP_BODY>' : '') . '</SII:RESPUESTA>';
}

prueba('SiiClient: semilla y token por SOAP, hacia el ambiente correcto', function (): void {
    $cert = Fixtures::certificateObject();
    [$c, $log] = clienteFalso([
        ['status' => 200, 'body' => soapRetorno('getSeed', respSii('<ESTADO>00</ESTADO>', '<SEMILLA>123456789</SEMILLA>'))],
        ['status' => 200, 'body' => soapRetorno('getToken', respSii('<ESTADO>00</ESTADO>', '<TOKEN>ABCDEF123</TOKEN>'))],
    ]);
    igual('ABCDEF123', $c->authenticate($cert), 'token');
    igual('https://maullin.sii.cl/DTEWS/CrSeed.jws', $log[0]['url'], 'semilla en certificación');
    igual('https://maullin.sii.cl/DTEWS/GetTokenFromSeed.jws', $log[1]['url'], 'token en certificación');
    afirma(str_contains($log[1]['body'], '&lt;Semilla&gt;123456789&lt;/Semilla&gt;') && str_contains($log[1]['body'], 'SignatureValue'), 'La solicitud de token debe llevar la semilla firmada.');
    [$p, $logp] = clienteFalso([['status' => 200, 'body' => soapRetorno('getSeed', respSii('<ESTADO>00</ESTADO>', '<SEMILLA>1</SEMILLA>'))]], SiiClient::PRODUCCION);
    $p->getSeed();
    igual('https://palena.sii.cl/DTEWS/CrSeed.jws', $logp[0]['url'], 'semilla en producción');
});
prueba('SiiClient: un ESTADO distinto de 00 o un fallo SOAP dan el motivo del SII', function (): void {
    [$c] = clienteFalso([['status' => 200, 'body' => soapRetorno('getSeed', respSii('<ESTADO>-3</ESTADO><GLOSA>Error interno</GLOSA>'))]]);
    lanza(fn () => $c->getSeed(), 'Error interno');
    [$c] = clienteFalso([['status' => 200, 'body' => '<e:Envelope xmlns:e="http://schemas.xmlsoap.org/soap/envelope/"><e:Body><e:Fault><faultstring>No autorizado</faultstring></e:Fault></e:Body></e:Envelope>']]);
    lanza(fn () => $c->getSeed(), 'No autorizado');
    [$c] = clienteFalso([['status' => 503, 'body' => '<html>Servicio no disponible</html>']]);
    lanza(fn () => $c->getSeed(), 'HTTP 503');
});
prueba('SiiClient: sube un EnvioDTE con los campos y la cookie que pide el SII y entrega el trackId', function (): void {
    [$dte, , $cert] = dteFirmado(Fixtures::datosFactura());
    $envio = (new \IngeInnova\DteChile\EnvioDteBuilder())->build([$dte], ['rutEmisor' => '76123456-0', 'rutEnvia' => '11111111-1', 'fechaResolucion' => '2026-01-01', 'numeroResolucion' => 0], $cert);
    [$c, $log] = clienteFalso([['status' => 200, 'body' => '<RECEPCIONDTE><STATUS>0</STATUS><TRACKID>987654</TRACKID></RECEPCIONDTE>']]);
    igual(['trackId' => '987654', 'status' => '0'], $c->uploadEnvioDte($envio, 'TKN', '11.111.111-1', '76.123.456-0'), 'resultado');
    $r = $log[0];
    igual('https://maullin.sii.cl/cgi_dte/UPL/DTEUpload', $r['url'], 'URL');
    igual(['11111111', '1', '76123456', '0'], [$r['body']['rutSender'], $r['body']['dvSender'], $r['body']['rutCompany'], $r['body']['dvCompany']], 'RUT');
    afirma(in_array('Cookie: TOKEN=TKN', $r['headers'], true), 'Debe ir el token en la cookie.');
    afirma(str_starts_with($r['body']['archivo']['content'], '<?xml') && str_contains($r['body']['archivo']['content'], '<EnvioDTE'), 'El archivo debe ser el EnvioDTE.');
    [$c] = clienteFalso([['status' => 200, 'body' => '<RECEPCIONDTE><STATUS>7</STATUS></RECEPCIONDTE>']]);
    lanza(fn () => $c->uploadEnvioDte($envio, 'TKN', '11111111-1', '76123456-0'), 'Esquema XML inválido');
});
prueba('SiiClient: consulta el estado de un envío y lo traduce', function (): void {
    [$c, $log] = clienteFalso([['status' => 200, 'body' => soapRetorno('getEstUp', respSii('<TRACKID>987654</TRACKID><ESTADO>EPR</ESTADO><GLOSA>Envio Procesado</GLOSA>', '<ACEPTADOS>2</ACEPTADOS><RECHAZADOS>1</RECHAZADOS><REPAROS>0</REPAROS>'))]]);
    $e = $c->getUploadStatus('76.123.456-0', '987654', 'TKN');
    igual(['EPR', 'Envío procesado.', 2, 1, 0, '987654'], [$e['estado'], $e['descripcion'], $e['aceptados'], $e['rechazados'], $e['reparos'], $e['trackId']], 'estado');
    afirma(str_contains($log[0]['body'], '<RutCompania>76123456</RutCompania>') && str_contains($log[0]['body'], '<TrackId>987654</TrackId>') && str_contains($log[0]['body'], '<Token>TKN</Token>'), 'Parámetros del servicio.');
    [$c] = clienteFalso([['status' => 200, 'body' => soapRetorno('getEstUp', respSii('<ESTADO>RSC</ESTADO><GLOSA>Rechazado por Error en Schema</GLOSA>'))]]);
    igual('Rechazado por error en el esquema.', $c->getUploadStatus('76123456-0', '1', 'T')['descripcion'], 'RSC');
    [$c] = clienteFalso([['status' => 200, 'body' => soapRetorno('getEstUp', respSii('<ESTADO>ZZZ</ESTADO><GLOSA>Estado nuevo del SII</GLOSA>'))]]);
    igual('Estado nuevo del SII', $c->getUploadStatus('76123456-0', '1', 'T')['descripcion'], 'estado desconocido usa la glosa del SII');
});
prueba('SiiClient: consulta el estado de un documento con la fecha en el formato del SII', function (): void {
    [$c, $log] = clienteFalso([['status' => 200, 'body' => soapRetorno('getEstDte', respSii('<ESTADO>DOK</ESTADO><GLOSA>Documento Recibido por el SII. Datos Coinciden con los Registrados</GLOSA>'))]]);
    $e = $c->getDteStatus('11111111-1', '76123456-0', '77111222-6', 33, 7, '2026-09-08', 297500, 'TKN');
    igual('DOK', $e['estado'], 'estado');
    foreach (['<FechaEmisionDte>08092026</FechaEmisionDte>', '<TipoDte>33</TipoDte>', '<FolioDte>7</FolioDte>', '<MontoDte>297500</MontoDte>', '<RutReceptor>77111222</RutReceptor>', '<DvReceptor>6</DvReceptor>'] as $trozo) {
        afirma(str_contains($log[0]['body'], $trozo), "Falta {$trozo} en la solicitud.");
    }
    lanza(fn () => $c->getDteStatus('11111111-1', '76123456-0', '77111222-6', 33, 7, '08-09-2026', 1, 'T'), 'AAAA-MM-DD');
});
prueba('SiiClient: boletas por la API REST (token, envío y estado)', function (): void {
    [$dte, , $cert] = boletaFirmada(datosBoleta());
    $envio = (new \IngeInnova\DteChile\EnvioDteBuilder())->buildBoleta([$dte], ['rutEmisor' => '76123456-0', 'rutEnvia' => '11111111-1', 'fechaResolucion' => '2026-01-01', 'numeroResolucion' => 0], $cert);
    [$c, $log] = clienteFalso([
        ['status' => 200, 'body' => respSii('<ESTADO>00</ESTADO>', '<SEMILLA>555</SEMILLA>')],
        ['status' => 200, 'body' => respSii('<ESTADO>00</ESTADO>', '<TOKEN>BOLTKN</TOKEN>')],
        ['status' => 200, 'body' => '{"rut_emisor":"76123456-0","rut_envia":"11111111-1","trackid":424242,"fecha_recepcion":"2026-09-08 10:00:00","estado":"REC","file":"EnvioBOLETA.xml"}'],
        ['status' => 200, 'body' => '{"rut_emisor":"76123456-0","trackid":424242,"estado":"EPR","estadistica":[{"tipo":39,"informados":1,"aceptados":1,"rechazados":0,"reparos":0}]}'],
    ]);
    $token = $c->authenticateBoleta($cert);
    igual('BOLTKN', $token, 'token');
    igual(['GET', 'https://apicert.sii.cl/recursos/v1/boleta.electronica.semilla'], [$log[0]['method'], $log[0]['url']], 'semilla');
    igual(['POST', 'https://apicert.sii.cl/recursos/v1/boleta.electronica.token'], [$log[1]['method'], $log[1]['url']], 'token');
    afirma(str_contains($log[1]['body'], '<Semilla>555</Semilla>'), 'Debe firmar la semilla.');
    $r = $c->uploadBoleta($envio, $token, '11111111-1', '76123456-0');
    igual(424242, $r['trackid'], 'trackid');
    igual('https://pangal.sii.cl/recursos/v1/boleta.electronica.envio', $log[2]['url'], 'envío en certificación');
    $e = $c->getBoletaUploadStatus('76.123.456-0', '424242', $token);
    igual('EPR', $e['estado'], 'estado');
    igual('https://apicert.sii.cl/recursos/v1/boleta.electronica.envio/76123456-0-424242', $log[3]['url'], 'consulta');
    [$p, $logp] = clienteFalso([['status' => 200, 'body' => '{"trackid":1}']], SiiClient::PRODUCCION);
    $p->uploadBoleta($envio, 'T', '11111111-1', '76123456-0');
    igual('https://rahue.sii.cl/recursos/v1/boleta.electronica.envio', $logp[0]['url'], 'envío en producción');
    [$m] = clienteFalso([['status' => 200, 'body' => 'No es JSON']]);
    lanza(fn () => $m->uploadBoleta($envio, 'T', '11111111-1', '76123456-0'), 'inesperado');
});
