<?php

declare(strict_types=1);

namespace IngeInnova\DteChile;

use DOMDocument;
use DOMElement;
use InvalidArgumentException;

/**
 * Único punto por el que entra texto al XML de un DTE.
 *
 * Por qué existe:
 *  - `DOMDocument::createElement('Tag', $texto)` NO escapa el `&`: con «Muebles & Cía» PHP descarta el valor completo (queda vacío) sin avisar.
 *    Aquí el texto siempre va en un nodo de texto, que sí se escapa bien.
 *  - El SII exige ISO-8859-1. Lo que no se puede representar ahí (raya larga, comillas tipográficas, emojis...) se normaliza o se rechaza con un
 *    error claro, en vez de salir como una referencia numérica que cambia los bytes que luego firma el timbre.
 */
final class Xml
{
    /** Signos tipográficos frecuentes (se copian y pegan desde Word o la web) y su equivalente seguro. */
    private const EQUIVALENTES = [
        "\u{2013}" => '-', "\u{2014}" => '-', "\u{2212}" => '-',
        "\u{2018}" => "'", "\u{2019}" => "'", "\u{201A}" => "'",
        "\u{201C}" => '"', "\u{201D}" => '"', "\u{201E}" => '"',
        "\u{2026}" => '...', "\u{2022}" => '-', "\u{20AC}" => 'EUR', "\u{2122}" => 'TM',
        "\u{200B}" => '', "\u{FEFF}" => '',
    ];

    /** Elemento `<$name>` con `$value` como texto (escapado) y ya normalizado a ISO-8859-1. */
    public static function el(DOMDocument $doc, string $name, string $value = ''): DOMElement
    {
        $element = $doc->createElement($name);
        $texto = self::latin1($value, $name);
        if ($texto !== '') {
            $element->appendChild($doc->createTextNode($texto));
        }

        return $element;
    }

    /**
     * Texto apto para ISO-8859-1: reemplaza los signos tipográficos comunes y rechaza lo que no tiene equivalente.
     *
     * @throws InvalidArgumentException Si hay un carácter que el SII no admite (indica el campo y el carácter).
     */
    public static function latin1(string $value, string $campo = 'texto'): string
    {
        $value = strtr($value, self::EQUIVALENTES);

        if (!mb_check_encoding($value, 'UTF-8')) {
            throw new InvalidArgumentException("El campo «{$campo}» no está en UTF-8 válido.");
        }
        // Caracteres de control: el XML 1.0 no los admite (salvo tabulación y saltos de línea).
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1) {
            throw new InvalidArgumentException("El campo «{$campo}» contiene caracteres de control que el XML no admite.");
        }
        $ida = mb_convert_encoding($value, 'ISO-8859-1', 'UTF-8');
        if (mb_convert_encoding($ida, 'UTF-8', 'ISO-8859-1') !== $value) {
            $mal = '';
            foreach (mb_str_split($value) as $caracter) {
                if (mb_convert_encoding(mb_convert_encoding($caracter, 'ISO-8859-1', 'UTF-8'), 'UTF-8', 'ISO-8859-1') !== $caracter) {
                    $mal = $caracter;
                    break;
                }
            }
            throw new InvalidArgumentException("El campo «{$campo}» contiene «{$mal}», que el SII no admite (solo ISO-8859-1: letras latinas, tildes, ñ y signos básicos).");
        }

        return $value;
    }
}
