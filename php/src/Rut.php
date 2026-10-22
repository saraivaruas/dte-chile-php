<?php

declare(strict_types=1);

namespace IngeInnova\DteChile;

/** El SII exige el RUT sin puntos, con guión y dígito verificador en mayúscula (ej. "76123456-7", "76123456-K") — nunca "76.123.456-7". */
final class Rut
{
    /** Acepta cualquier formato de entrada ("76.123.456-7", "76123456-7", con o sin espacios) y devuelve el formato que exige el SII. */
    public static function toDteFormat(string $rut): string
    {
        $clean = strtoupper(str_replace(['.', ' '], '', trim($rut)));
        if (!str_contains($clean, '-')) {
            $clean = substr($clean, 0, -1) . '-' . substr($clean, -1);
        }

        return $clean;
    }

    /** Dígito verificador módulo 11 — usado para validar antes de armar el DTE, ya que el SII rechaza el documento entero si el RUT del receptor está mal. */
    public static function isValid(string $rut): bool
    {
        $clean = self::toDteFormat($rut);
        if (!preg_match('/^(\d{1,8})-([\dK])$/', $clean, $m)) {
            return false;
        }

        [, $number, $dv] = $m;
        $sum = 0;
        $multiplier = 2;
        foreach (array_reverse(str_split($number)) as $digit) {
            $sum += ((int) $digit) * $multiplier;
            $multiplier = $multiplier === 7 ? 2 : $multiplier + 1;
        }

        $remainder = 11 - ($sum % 11);
        $expectedDv = match ($remainder) {
            11 => '0',
            10 => 'K',
            default => (string) $remainder,
        };

        return $dv === $expectedDv;
    }
}
