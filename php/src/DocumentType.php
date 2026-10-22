<?php

declare(strict_types=1);

namespace IngeInnova\DteChile;

/** Códigos de tipo de documento tributario electrónico del SII. */
final class DocumentType
{
    public const FACTURA_ELECTRONICA = 33;
    public const FACTURA_NO_AFECTA_EXENTA = 34;
    public const BOLETA_ELECTRONICA = 39;
    public const BOLETA_NO_AFECTA_EXENTA = 41;
    public const LIQUIDACION_FACTURA = 43;
    public const FACTURA_COMPRA = 46;
    public const GUIA_DESPACHO = 52;
    public const NOTA_DEBITO = 56;
    public const NOTA_CREDITO = 61;
    public const FACTURA_EXPORTACION = 110;
    public const NOTA_DEBITO_EXPORTACION = 111;
    public const NOTA_CREDITO_EXPORTACION = 112;

    public const LABELS = [
        self::FACTURA_ELECTRONICA => 'Factura Electrónica',
        self::FACTURA_NO_AFECTA_EXENTA => 'Factura No Afecta o Exenta Electrónica',
        self::BOLETA_ELECTRONICA => 'Boleta Electrónica',
        self::BOLETA_NO_AFECTA_EXENTA => 'Boleta No Afecta o Exenta Electrónica',
        self::LIQUIDACION_FACTURA => 'Liquidación Factura Electrónica',
        self::FACTURA_COMPRA => 'Factura de Compra Electrónica',
        self::GUIA_DESPACHO => 'Guía de Despacho Electrónica',
        self::NOTA_DEBITO => 'Nota de Débito Electrónica',
        self::NOTA_CREDITO => 'Nota de Crédito Electrónica',
        self::FACTURA_EXPORTACION => 'Factura de Exportación Electrónica',
        self::NOTA_DEBITO_EXPORTACION => 'Nota de Débito de Exportación Electrónica',
        self::NOTA_CREDITO_EXPORTACION => 'Nota de Crédito de Exportación Electrónica',
    ];

    public static function label(int $code): string
    {
        return self::LABELS[$code] ?? "Tipo {$code}";
    }

    /** true para las boletas (39 y 41): tienen su propio esquema y su propio envío, distintos de los demás documentos. */
    public static function isBoleta(int $code): bool
    {
        return $code === self::BOLETA_ELECTRONICA || $code === self::BOLETA_NO_AFECTA_EXENTA;
    }
}
