<?php

declare(strict_types=1);

namespace IngeInnova\DteChile\Tests;

use IngeInnova\DteChile\ArchivoFolios;
use IngeInnova\DteChile\DocumentType;
use IngeInnova\DteChile\EstadoDocumento;
use IngeInnova\DteChile\Emisor;
use IngeInnova\DteChile\Facturador;
use IngeInnova\DteChile\Linea;
use IngeInnova\DteChile\MemoriaFolios;
use IngeInnova\DteChile\Receptor;
use IngeInnova\DteChile\Referencia;
use IngeInnova\DteChile\SiiClient;

function facturador(array $tipos = [33], ?SiiClient $sii = null, int $hasta = 100): array
{
    $cert = Fixtures::certificateObject();
    $f = new Facturador(
        new Emisor('76.123.456-0', 'Inge Innova SpA', 'Servicios de Ingeniería', 711001, 'Av. Siempre Viva 123', 'Providencia', 'Santiago'),
        $cert, new MemoriaFolios(), SiiClient::CERTIFICACION, ['fecha' => '2026-01-01', 'numero' => 0], $sii,
    );
    $cafs = [];
    foreach ($tipos as $t) {
        $cafs[$t] = Fixtures::caf($t, 1, $hasta);
        $f->agregarCaf($cafs[$t]['caf']);
    }

    return [$f, $cafs];
}
function cliente(): Receptor
{
    return new Receptor('77.111.222-6', 'Cliente de Prueba Ltda', 'Comercio', 'Calle Falsa 456', 'Las Condes');
}

prueba('Facturador: una factura sale timbrada, firmada, con folio automático y válida contra el XSD', function (): void {
    [$f, $cafs] = facturador();
    $d1 = $f->factura(cliente(), [new Linea('Consultoría técnica', 10, 25000)], ['fechaEmision' => '2026-09-08']);
    $d2 = $f->factura(cliente(), [new Linea('Consultoría técnica', 1, 1000)], ['fechaEmision' => '2026-09-08']);
    igual([1, 2], [$d1->folio, $d2->folio], 'folios consecutivos');
    igual(297500, $d1->montoTotal, 'total con IVA (250.000 + 47.500)');
    igual('76123456-0', $d1->rutEmisor, 'rut emisor');
    validaXsd(bytes($d1->xml), 'dte/DTE_v10.xsd');
    verificaTed($d1->toXml(), $cafs[33]['publicPem']);
    validaXsd(bytes($f->sobre([$d1, $d2])), 'dte/EnvioDTE_v10.xsd');
    afirma(str_starts_with($d1->timbreSvg(), '<svg') || str_contains($d1->timbreSvg(), '<svg'), 'El timbre debe salir como SVG.');
});
prueba('Facturador: la fecha de emisión por defecto es hoy en Chile', function (): void {
    [$f] = facturador();
    $hoy = (new \DateTimeImmutable('now', new \DateTimeZone('America/Santiago')))->format('Y-m-d');
    igual($hoy, $f->factura(cliente(), [new Linea('Servicio', 1, 1000)])->fechaEmision, 'fecha');
});
prueba('Facturador: factura exenta, nota de crédito, nota de débito y guía de despacho cumplen el esquema', function (): void {
    [$f] = facturador([34, 61, 56, 52]);
    $ref = [new Referencia(33, 1, '2026-09-08', Referencia::ANULA, 'Anula factura')];
    $docs = [
        $f->facturaExenta(cliente(), [new Linea('Capacitación exenta', 1, 50000, true)], ['fechaEmision' => '2026-09-08']),
        $f->notaCredito(cliente(), [new Linea('Consultoría técnica', 10, 25000)], $ref, ['fechaEmision' => '2026-09-08']),
        $f->notaDebito(cliente(), [new Linea('Intereses', 1, 5000)], $ref, ['fechaEmision' => '2026-09-08']),
        $f->guiaDespacho(cliente(), [new Linea('Equipo', 2, 10000)], 1, ['fechaEmision' => '2026-09-08']),
    ];
    foreach ($docs as $d) {
        validaXsd(bytes($d->xml), 'dte/DTE_v10.xsd');
    }
    igual([34, 61, 56, 52], array_map(fn ($d) => $d->tipo, $docs), 'tipos');
    validaXsd(bytes($f->sobre($docs)), 'dte/EnvioDTE_v10.xsd');
});
prueba('Facturador: la boleta usa precios con IVA incluido, receptor genérico y su propio sobre', function (): void {
    [$f] = facturador([39, 41, 33]);
    $b = $f->boleta([new Linea('Café', 2, 2500), new Linea('Pan', 1, 1190)], null, ['fechaEmision' => '2026-09-08']);
    igual(6190, $b->montoTotal, 'total boleta');
    igual('66666666-6', $b->rutReceptor, 'receptor genérico');
    validaXsd(bytes($f->sobre([$b])), 'envio_bol/EnvioBOLETA_v11.xsd');
    $e = $f->boletaExenta([new Linea('Libro', 1, 9990, true)], null, ['fechaEmision' => '2026-09-08']);
    igual(DocumentType::BOLETA_NO_AFECTA_EXENTA, $e->tipo, 'tipo 41');
    lanza(fn () => $f->sobre([$b, $f->factura(cliente(), [new Linea('X', 1, 1000)], ['fechaEmision' => '2026-09-08'])]), 'aparte');
});
prueba('Facturador: errores claros (sin CAF, nota sin referencia, folios agotados, CAF ajeno, datos inválidos)', function (): void {
    [$f] = facturador([33, 61], null, 2);
    lanza(fn () => $f->guiaDespacho(cliente(), [new Linea('X', 1, 1)], 1), 'No hay un CAF cargado');
    lanza(fn () => $f->notaCredito(cliente(), [new Linea('X', 1, 1000)], []), 'referencia');
    $f->factura(cliente(), [new Linea('X', 1, 1000)]);
    $f->factura(cliente(), [new Linea('X', 1, 1000)]);
    lanza(fn () => $f->factura(cliente(), [new Linea('X', 1, 1000)]), 'Se agotaron los folios');
    lanza(fn () => $f->factura(cliente(), []), 'al menos una línea');
    lanza(fn () => new Receptor('12345678-0', 'X'), 'no es válido');
    lanza(fn () => new Linea('X', 0, 100), 'cantidad');
    $otro = Fixtures::caf(33, 1, 10);
    $xml = str_replace('76123456-0', '99999999-9', $otro['xml']);
    lanza(fn () => $f->agregarCaf(\IngeInnova\DteChile\Caf::fromXml($xml)), 'otro contribuyente');
});
prueba('ArchivoFolios: persiste el avance entre instancias', function (): void {
    $ruta = sys_get_temp_dir() . '/folios_' . bin2hex(random_bytes(4)) . '.json';
    igual(5, (new ArchivoFolios($ruta))->siguiente(33, 5, 10), 'primero');
    igual(6, (new ArchivoFolios($ruta))->siguiente(33, 5, 10), 'segundo');
    unlink($ruta);
});
prueba('Facturador: enviar autentica una vez, sube el sobre y devuelve el trackId', function (): void {
    [$sii, $log] = clienteFalso([
        ['status' => 200, 'body' => soapRetorno('getSeed', respSii('<ESTADO>00</ESTADO>', '<SEMILLA>123</SEMILLA>'))],
        ['status' => 200, 'body' => soapRetorno('getToken', respSii('<ESTADO>00</ESTADO>', '<TOKEN>TKN</TOKEN>'))],
        ['status' => 200, 'body' => '<RECEPCIONDTE><STATUS>0</STATUS><TRACKID>555</TRACKID></RECEPCIONDTE>'],
        ['status' => 200, 'body' => '<RECEPCIONDTE><STATUS>0</STATUS><TRACKID>556</TRACKID></RECEPCIONDTE>'],
    ]);
    [$f] = facturador([33], $sii);
    $d = $f->factura(cliente(), [new Linea('Servicio', 1, 1000)], ['fechaEmision' => '2026-09-08']);
    $r = $f->enviar([$d]);
    igual('555', $r->trackId, 'trackId');
    igual('556', $f->enviar([$d])->trackId, 'segundo envío reutiliza el token');
    igual(4, count($log), 'solo una autenticación (2 llamadas) + 2 subidas');
    afirma(in_array('Cookie: TOKEN=TKN', $log[2]['headers'], true), 'Debe subir con el token.');
});
prueba('Facturador::probarConexion informa por servicio, sin lanzar y sin exponer tokens', function (): void {
    [$sii] = clienteFalso([
        ['status' => 200, 'body' => soapRetorno('getSeed', respSii('<ESTADO>00</ESTADO>', '<SEMILLA>1</SEMILLA>'))],
        ['status' => 200, 'body' => soapRetorno('getToken', respSii('<ESTADO>00</ESTADO>', '<TOKEN>SECRETO</TOKEN>'))],
        ['status' => 200, 'body' => respSii('<ESTADO>-3</ESTADO><GLOSA>Servicio caído</GLOSA>')],
    ]);
    [$f] = facturador([], $sii);
    $r = $f->probarConexion();
    afirma($r->facturas && !$r->boletas && !$r->ok(), 'Facturas OK y boletas con falla.');
    igual('11111111-1', $r->rutTitular, 'titular');
    afirma(str_contains($r->errores['boletas'] ?? '', 'Servicio caído'), 'Debe traer el motivo del SII.');
    afirma(!str_contains(json_encode($r->errores), 'SECRETO'), 'No debe filtrar el token.');
});
function auth(): array
{
    return [
        ['status' => 200, 'body' => soapRetorno('getSeed', respSii('<ESTADO>00</ESTADO>', '<SEMILLA>1</SEMILLA>'))],
        ['status' => 200, 'body' => soapRetorno('getToken', respSii('<ESTADO>00</ESTADO>', '<TOKEN>TKN</TOKEN>'))],
    ];
}
$ok = ['status' => 200, 'body' => '<RECEPCIONDTE><STATUS>0</STATUS><TRACKID>777</TRACKID></RECEPCIONDTE>'];
$linea = static fn () => [new Linea('Servicio', 1, 1000)];

prueba('emitirYEnviar: emite y envía en una llamada y deja el seguimiento', function () use ($ok, $linea): void {
    [$sii, $log] = clienteFalso([...auth(), $ok]);
    [$f] = facturador([33], $sii);
    $r = $f->emitirYEnviar(33, cliente(), $linea(), [], ['fechaEmision' => '2026-09-08']);
    igual(EstadoDocumento::Enviado, $r->estado, 'estado');
    igual('777', $r->trackId, 'trackId');
    igual(1, $r->documento->folio, 'folio');
    igual(1, $r->intentos, 'intentos');
    afirma($r->exitoso(), 'Debe ser exitoso.');
});
prueba('emitirYEnviar: reintenta ante un fallo temporal del SII y lo logra', function () use ($ok, $linea): void {
    [$sii, $log] = clienteFalso([...auth(), ['status' => 503, 'body' => 'caído'], $ok]);
    [$f] = facturador([33], $sii);
    $r = $f->reintentos(2, 0)->emitirYEnviar(33, cliente(), $linea(), [], ['fechaEmision' => '2026-09-08']);
    igual(EstadoDocumento::Enviado, $r->estado, 'estado');
    igual(2, $r->intentos, 'intentos');
});
prueba('emitirYEnviar: si el SII no responde queda Emitido (folio usado) y se puede reenviar', function () use ($ok, $linea): void {
    [$sii] = clienteFalso([...auth(), ['status' => 503, 'body' => 'x'], ['status' => 503, 'body' => 'x'], ['status' => 503, 'body' => 'x'], $ok]);
    [$f] = facturador([33], $sii);
    $r = $f->reintentos(2, 0)->emitirYEnviar(33, cliente(), $linea(), [], ['fechaEmision' => '2026-09-08']);
    igual(EstadoDocumento::Emitido, $r->estado, 'estado');
    afirma(!$r->exitoso() && str_contains($r->mensaje, 'reenvíelo'), 'Debe explicar qué hacer: ' . $r->mensaje);
    $r2 = $f->reenviar($r->documento);
    igual(EstadoDocumento::Enviado, $r2->estado, 'tras reenviar');
    igual($r->documento->folio, $r2->documento->folio, 'mismo folio, no se emite otro');
});
prueba('emitirYEnviar: un rechazo del SII no se reintenta y se explica en lenguaje de usuario', function () use ($linea): void {
    [$sii] = clienteFalso([...auth(), ['status' => 200, 'body' => '<RECEPCIONDTE><STATUS>7</STATUS></RECEPCIONDTE>']]);
    [$f] = facturador([33], $sii);
    $r = $f->reintentos(3, 0)->emitirYEnviar(33, cliente(), $linea(), [], ['fechaEmision' => '2026-09-08']);
    igual(EstadoDocumento::Rechazado, $r->estado, 'estado');
    igual(1, $r->intentos, 'sin reintentos');
    afirma(str_contains($r->mensaje, 'esquema'), 'Mensaje: ' . $r->mensaje);
    afirma(str_contains((string) $r->detalleTecnico, 'STATUS=7'), 'Conserva el detalle técnico.');
});
prueba('consultar: interpreta el estado del SII (aceptado, con reparos, rechazado, en proceso)', function () use ($ok, $linea): void {
    $estado = static fn (string $e, int $a, int $r, int $p) => ['status' => 200, 'body' => soapRetorno('getEstUp', respSii("<TRACKID>777</TRACKID><ESTADO>{$e}</ESTADO><GLOSA>x</GLOSA>", "<ACEPTADOS>{$a}</ACEPTADOS><RECHAZADOS>{$r}</RECHAZADOS><REPAROS>{$p}</REPAROS>"))];
    [$sii] = clienteFalso([...auth(), $ok, $estado('EPR', 1, 0, 0), $estado('EPR', 0, 0, 1), $estado('EPR', 0, 1, 0), $estado('REC', 0, 0, 0), $estado('RFR', 0, 0, 0)]);
    [$f] = facturador([33], $sii);
    $r = $f->emitirYEnviar(33, cliente(), $linea(), [], ['fechaEmision' => '2026-09-08']);
    igual(EstadoDocumento::Aceptado, $f->consultar($r)->estado, 'aceptado');
    igual(EstadoDocumento::ConReparos, $f->consultar($r)->estado, 'reparos');
    igual(EstadoDocumento::Rechazado, $f->consultar($r)->estado, 'rechazado');
    $enProceso = $f->consultar($r);
    igual(EstadoDocumento::Enviado, $enProceso->estado, 'en proceso');
    afirma(!$enProceso->estado->esFinal(), 'En proceso no es final.');
    $rf = $f->consultar($r);
    igual(EstadoDocumento::Rechazado, $rf->estado, 'firma');
    afirma(str_contains($rf->mensaje, 'firma'), 'Explica la firma: ' . $rf->mensaje);
});
