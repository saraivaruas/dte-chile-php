<?php

declare(strict_types=1);

namespace IngeInnova\DteChile\Tests;

use DOMDocument;
use IngeInnova\DteChile\Certificate;
use IngeInnova\DteChile\SiiDocuments;

const DECLARACION_RECIBO = 'El acuse de recibo que se declara en este acto, de acuerdo a lo dispuesto en la letra b) del Art. 4, y la letra c) del Art. 5 de la Ley 19.983, acredita que la entrega de mercaderias o servicio(s) prestado(s) ha(n) sido recibido(s).';

function caratulaLibro(string $operacion = 'VENTA'): array
{
    return ['RutEmisorLibro' => '76123456-0', 'RutEnvia' => '11111111-1', 'PeriodoTributario' => '2026-09', 'FchResol' => '2026-01-01', 'NroResol' => 0, 'TipoOperacion' => $operacion, 'TipoLibro' => 'MENSUAL', 'TipoEnvio' => 'TOTAL'];
}

prueba('Libro de ventas (IECV): se arma, se firma y cumple el esquema oficial', function (): void {
    $cert = Fixtures::certificateObject();
    $doc = (new SiiDocuments())->libroCompraVenta(['EnvioLibro' => [
        'Caratula' => caratulaLibro(),
        'ResumenPeriodo' => ['TotalesPeriodo' => [['TpoDoc' => 33, 'TotDoc' => 1, 'TotMntExe' => 0, 'TotMntNeto' => 250000, 'TotMntIVA' => 47500, 'TotMntTotal' => 297500]]],
        'Detalle' => [['TpoDoc' => 33, 'NroDoc' => 1, 'FchDoc' => '2026-09-08', 'RUTDoc' => '77111222-6', 'RznSoc' => 'Cliente de Prueba Ltda', 'MntNeto' => 250000, 'MntIVA' => 47500, 'MntTotal' => 297500]],
    ]], $cert);
    validaXsd(bytes($doc), 'iecv/LibroCV_v10.xsd');
    igual(1, verificaXmlDsig(bytes($doc), $cert->certificatePem()), 'firmas');
});
prueba('Libro de compras: tipo de operación y de documento inválidos se rechazan con el campo', function (): void {
    lanza(fn () => (new SiiDocuments())->libroCompraVenta(['EnvioLibro' => ['Caratula' => caratulaLibro('ARRIENDO')]], Fixtures::certificateObject()), 'TipoOperacion');
});
prueba('Libro de guías de despacho: se arma, se firma y cumple el esquema oficial', function (): void {
    $cert = Fixtures::certificateObject();
    $doc = (new SiiDocuments())->libroGuia(['EnvioLibro' => [
        'Caratula' => ['RutEmisorLibro' => '76123456-0', 'RutEnvia' => '11111111-1', 'PeriodoTributario' => '2026-09', 'FchResol' => '2026-01-01', 'NroResol' => 0, 'TipoLibro' => 'ESPECIAL', 'TipoEnvio' => 'TOTAL', 'FolioNotificacion' => 1],
        'ResumenPeriodo' => ['TotFolAnulado' => 0, 'TotGuiaAnulada' => 0, 'TotGuiaVenta' => 1, 'TotMntGuiaVta' => 1000, 'TotTraslado' => [['TpoTraslado' => 2, 'CantGuia' => 1, 'MntGuia' => 0]]],
        'Detalle' => [['Folio' => 1, 'TpoOper' => 1, 'FchDoc' => '2026-09-08', 'RUTDoc' => '77111222-6', 'RznSoc' => 'Cliente', 'MntNeto' => 840, 'TasaImp' => 19, 'IVA' => 160, 'MntTotal' => 1000]],
    ]], $cert);
    validaXsd(bytes($doc), 'lgd/LibroGuia_v10.xsd');
});
prueba('Reporte de consumo de folios (RCOF): se arma, se firma y cumple el esquema oficial', function (): void {
    $cert = Fixtures::certificateObject();
    $doc = (new SiiDocuments())->consumoFolios(['DocumentoConsumoFolios' => [
        'Caratula' => ['RutEmisor' => '76123456-0', 'RutEnvia' => '11111111-1', 'FchResol' => '2026-01-01', 'NroResol' => 0, 'FchInicio' => '2026-09-08', 'FchFinal' => '2026-09-08', 'SecEnvio' => 1],
        'Resumen' => [['TipoDocumento' => 39, 'MntNeto' => 5202, 'MntIva' => 988, 'TasaIVA' => 19, 'MntExento' => 0, 'MntTotal' => 6190, 'FoliosEmitidos' => 3, 'FoliosAnulados' => 1, 'FoliosUtilizados' => 2, 'RangoUtilizados' => [['Inicial' => 1, 'Final' => 2]], 'RangoAnulados' => [['Inicial' => 3, 'Final' => 3]]]],
    ]], $cert);
    validaXsd(bytes($doc), 'consumo_folios/ConsumoFolio_v10.xsd');
    igual(1, verificaXmlDsig(bytes($doc), $cert->certificatePem()), 'firmas');
});
prueba('Libro de boletas: se arma, se firma y cumple el esquema oficial', function (): void {
    $cert = Fixtures::certificateObject();
    $doc = (new SiiDocuments())->libroBoleta(['EnvioLibro' => [
        'Caratula' => ['RutEmisorLibro' => '76123456-0', 'RutEnvia' => '11111111-1', 'PeriodoTributario' => '2026-09', 'FchResol' => '2026-01-01', 'NroResol' => 0, 'TipoLibro' => 'ESPECIAL', 'TipoEnvio' => 'TOTAL', 'FolioNotificacion' => 1],
        'ResumenPeriodo' => ['TotalesPeriodo' => [['TpoDoc' => 39, 'TotalesServicio' => [['TpoServ' => 3, 'PeriodoDevengado' => '2026-09', 'TotDoc' => 1, 'TotMntExe' => 0, 'TotMntNeto' => 5202, 'TotMntIVA' => 988, 'TasaIVA' => 19, 'TotMntTotal' => 6190]]]]],
    ]], $cert);
    // El esquema oficial LibroBOLETA_v10.xsd declara su <Signature> en el espacio de nombres del SII y no en el de XMLDSig (distinto de todos los demás
    // esquemas): el documento firmado no puede validar completo contra él. Se valida la misma firma con ese espacio de nombres, y se verifica aparte la real.
    $xml = str_replace('xmlns="http://www.w3.org/2000/09/xmldsig#"', 'xmlns="http://www.sii.cl/SiiDte"', bytes($doc));
    validaXsd($xml, 'libro_bol/LibroBOLETA_v10.xsd');
    igual(1, verificaXmlDsig(bytes($doc), $cert->certificatePem()), 'firmas');
});
prueba('Envío de recibos (Ley 19.983): cada recibo y el conjunto quedan firmados y válidos', function (): void {
    $cert = Fixtures::certificateObject();
    $recibo = fn (int $folio): array => ['DocumentoRecibo' => ['TipoDoc' => 33, 'Folio' => $folio, 'FchEmis' => '2026-09-08', 'RUTEmisor' => '76123456-0', 'RUTRecep' => '77111222-6', 'MntTotal' => 297500, 'Recinto' => 'Av. Calle Falsa 456, Las Condes', 'RutFirma' => '11111111-1', 'Declaracion' => DECLARACION_RECIBO]];
    $doc = (new SiiDocuments())->envioRecibos(['RutResponde' => '77111222-6', 'RutRecibe' => '76123456-0', 'NmbContacto' => 'Ana Pérez'], [$recibo(1), $recibo(2)], $cert);
    validaXsd(bytes($doc), '19983/EnvioRecibos_v10.xsd');
    igual(3, verificaXmlDsig(bytes($doc), $cert->certificatePem()), 'firmas (2 recibos + el conjunto)');
});
prueba('Respuesta de intercambio: recepción de envío firmada y válida', function (): void {
    $cert = Fixtures::certificateObject();
    $doc = (new SiiDocuments())->respuestaDte(['Resultado' => [
        'Caratula' => ['RutResponde' => '77111222-6', 'RutRecibe' => '76123456-0', 'IdRespuesta' => 1, 'NroDetalles' => 1],
        'RecepcionEnvio' => [[
            'NmbEnvio' => 'envio.xml', 'FchRecep' => '2026-09-08T10:00:00', 'CodEnvio' => 1, 'EnvioDTEID' => 'SetDoc', 'Digest' => 'YWJjZGVm',
            'RutEmisor' => '76123456-0', 'RutReceptor' => '77111222-6', 'EstadoRecepEnv' => 0, 'RecepEnvGlosa' => 'Envio Recibido Conforme', 'NroDTE' => 1,
            'RecepcionDTE' => [['TipoDTE' => 33, 'Folio' => 1, 'FchEmis' => '2026-09-08', 'RUTEmisor' => '76123456-0', 'RUTRecep' => '77111222-6', 'MntTotal' => 297500, 'EstadoRecepDTE' => 0, 'RecepDTEGlosa' => 'DTE Recibido OK']],
        ]],
    ]], $cert);
    validaXsd(bytes($doc), 'ic/RespuestaEnvioDTE_v10.xsd');
    igual(1, verificaXmlDsig(bytes($doc), $cert->certificatePem()), 'firmas');
});
prueba('Cesión de factura (AEC): el DTE, la cesión y el archivo quedan firmados y válidos', function (): void {
    [$dte, , $cert] = dteFirmado(Fixtures::datosFactura(7));
    $doc = (new SiiDocuments())->aec(
        ['RutCedente' => '76123456-0', 'RutCesionario' => '77111222-6'],
        $dte,
        [[
            'SeqCesion' => 1,
            'IdDTE' => ['TipoDTE' => 33, 'RUTEmisor' => '76123456-0', 'RUTReceptor' => '77111222-6', 'Folio' => 7, 'FchEmis' => '2026-09-08', 'MntTotal' => 297500],
            'Cedente' => ['RUT' => '76123456-0', 'RazonSocial' => 'Inge Innova SpA', 'Direccion' => 'Av. Siempre Viva 123', 'eMail' => 'facturas@ejemplo.cl', 'RUTAutorizado' => [['RUT' => '11111111-1', 'Nombre' => 'Persona de Prueba']]],
            'Cesionario' => ['RUT' => '77111222-6', 'RazonSocial' => 'Cliente de Prueba Ltda', 'Direccion' => 'Calle Falsa 456', 'eMail' => 'cesion@ejemplo.cl'],
            'MontoCesion' => 297500, 'UltimoVencimiento' => '2026-10-08',
        ]],
        $cert
    );
    validaXsd(bytes($doc), 'cesion/AEC_v10.xsd');
    igual(4, verificaXmlDsig(bytes($doc), $cert->certificatePem()), 'firmas (Documento del DTE, DTE cedido, cesión y AEC)');
});
prueba('Reglas por tipo: la nota de crédito exige referencia y la guía de despacho el tipo de traslado', function (): void {
    lanza(fn () => (new \IngeInnova\DteChile\DteBuilder())->build(61, Fixtures::datosFactura()), 'referencia');
    lanza(fn () => (new \IngeInnova\DteChile\DteBuilder())->build(52, Fixtures::datosFactura()), 'tipo de traslado');
    (new \IngeInnova\DteChile\DteBuilder())->build(61, Fixtures::datosFactura(1, ['referencias' => [['tipoDocumento' => 33, 'folio' => 7, 'fecha' => '2026-09-08', 'codigo' => 1, 'razon' => 'Anula factura']]]));
    (new \IngeInnova\DteChile\DteBuilder())->build(52, Fixtures::datosFactura(1, ['indicadorTraslado' => 1]));
});
