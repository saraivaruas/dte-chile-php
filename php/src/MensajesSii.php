<?php

declare(strict_types=1);

namespace IngeInnova\DteChile;

/** Traduce los códigos y mensajes del SII a una explicación y una acción concreta, en lenguaje de usuario. */
final class MensajesSii
{
    /** Fragmento del mensaje original => explicación con qué hacer. */
    private const REGLAS = [
        'STATUS=1' => 'El titular del certificado no tiene permiso para enviar por esta empresa. Autorícelo en el SII (Mi SII → Autorizar representantes de factura electrónica).',
        'STATUS=2' => 'El archivo es demasiado grande o demasiado pequeño. Envíe menos documentos por sobre.',
        'STATUS=3' => 'El archivo llegó cortado. Reintente el envío.',
        'STATUS=5' => 'La sesión con el SII venció. La biblioteca la renueva y reintenta sola; si persiste, revise la hora del servidor.',
        'STATUS=6' => 'La empresa no está autorizada a emitir documentos electrónicos. Revise su estado de postulación y certificación en el SII.',
        'STATUS=7' => 'El SII dice que el XML no cumple el esquema. Revise los datos del documento (largos, caracteres y campos obligatorios).',
        'STATUS=8' => 'El SII dice que la firma es inválida. Revise que el certificado sea del titular autorizado y que no esté vencido.',
        'STATUS=9' => 'El sistema del SII está bloqueado. Reintente más tarde.',
        'RSC' => 'Rechazado por error en el esquema del XML. Revise los datos del documento.',
        'RFR' => 'Rechazado por error en la firma. Revise el certificado.',
        'RCT' => 'Rechazado por error en la carátula (RUT del emisor, RUT que envía o resolución). Revise los datos de la empresa y la resolución.',
        'RCH' => 'El SII rechazó el envío. Revise el detalle en el sitio del SII con el número de seguimiento.',
        'HTTP 5' => 'El SII está con problemas temporales. El documento quedó emitido; reenvíelo en unos minutos.',
        'HTTP 429' => 'El SII pidió espaciar las solicitudes. Reintente en unos minutos.',
        'HTTP 401' => 'El SII no reconoció la sesión. Reintente; si persiste, revise el certificado.',
        'no está autorizado por este CAF' => 'El folio no pertenece al CAF cargado. Cargue el CAF correcto o solicite uno nuevo.',
        'Se agotaron los folios' => 'No quedan folios. Solicite un CAF nuevo en el SII.',
        'timed out' => 'El SII no respondió a tiempo. El documento quedó emitido; reenvíelo en unos minutos.',
        'No fue posible conectar' => 'No hay conexión a internet o el SII no responde. El documento quedó emitido; reenvíelo cuando haya conexión.',
        'Could not resolve' => 'No hay conexión a internet o el SII no responde. El documento quedó emitido; reenvíelo cuando haya conexión.',
    ];

    public static function explicar(string $mensajeOriginal): string
    {
        foreach (self::REGLAS as $fragmento => $explicacion) {
            if (stripos($mensajeOriginal, $fragmento) !== false) {
                return $explicacion;
            }
        }

        return 'El SII respondió algo inesperado: ' . mb_substr($mensajeOriginal, 0, 200);
    }

    /** ¿Vale la pena reintentar el mismo envío? Solo los fallos temporales; un rechazo del SII no cambia por reintentar. */
    public static function esTemporal(string $mensajeOriginal): bool
    {
        foreach (['HTTP 5', 'HTTP 429', 'timed out', 'Could not resolve', 'No fue posible conectar', 'cURL', 'STATUS=3', 'STATUS=9', 'Connection'] as $f) {
            if (stripos($mensajeOriginal, $f) !== false) {
                return true;
            }
        }

        return false;
    }
}
