<?php

declare(strict_types=1);

namespace IngeInnova\DteChile;

use RuntimeException;

/**
 * Especificación de los documentos del SII, generada desde sus esquemas oficiales (tools/gen_spec.py → resources/spec/*.json).
 * Describe, por elemento: orden de los hijos, mínimo y máximo de ocurrencias, atributos y las restricciones de cada tipo simple.
 */
final class Spec
{
    /** @var array<string, array<string, mixed>> */
    private static array $cache = [];

    /** @return array<string, mixed> Árbol del elemento raíz (p. ej. «dte», «envio_dte», «libro_compra_venta», «consumo_folios»). */
    public static function load(string $name): array
    {
        if (!isset(self::$cache[$name])) {
            if (!preg_match('/^[a-z_]+$/', $name)) {
                throw new RuntimeException("Nombre de especificación no válido: {$name}.");
            }
            $path = __DIR__ . '/../resources/spec/' . $name . '.json';
            $json = is_file($path) ? file_get_contents($path) : false;
            $data = $json === false ? null : json_decode($json, true);
            if (!is_array($data)) {
                throw new RuntimeException("No se encontró o no se pudo leer la especificación «{$name}».");
            }
            self::$cache[$name] = $data;
        }

        return self::$cache[$name];
    }

    /**
     * Nodo hijo directo de $node con ese nombre (buscando también dentro de las alternativas).
     *
     * @param array<string, mixed> $node
     * @return array<string, mixed>|null
     */
    public static function child(array $node, string $name): ?array
    {
        foreach (self::flatten($node['children'] ?? []) as $c) {
            if (($c['name'] ?? null) === $name) {
                return $c;
            }
        }

        return null;
    }

    /**
     * Nombres de los hijos directos de $node (incluye las alternativas).
     *
     * @param array<string, mixed> $node
     * @return list<string>
     */
    public static function childNames(array $node): array
    {
        return array_values(array_map(static fn (array $c): string => (string) $c['name'], self::flatten($node['children'] ?? [])));
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private static function flatten(array $items): array
    {
        $out = [];
        foreach ($items as $i) {
            if (isset($i['choice'])) {
                array_push($out, ...self::flatten($i['choice']));
            } elseif (isset($i['sequence'])) {
                array_push($out, ...self::flatten($i['sequence']));
            } else {
                $out[] = $i;
            }
        }

        return $out;
    }
}
