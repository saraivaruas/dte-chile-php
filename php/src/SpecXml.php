<?php

declare(strict_types=1);

namespace IngeInnova\DteChile;

use DOMDocument;
use DOMElement;
use InvalidArgumentException;

/**
 * Arma el XML de un documento del SII a partir de arrays, guiado por la especificación oficial (ver {@see Spec}).
 *
 * Qué garantiza:
 *  - Cada elemento sale en el orden que exige el esquema, aunque el array venga desordenado.
 *  - Falta de un campo obligatorio, un campo que no existe (típico error de tipeo), más repeticiones de las permitidas, un valor fuera de largo,
 *    patrón, enumeración o rango: todos dan un error claro con la ruta del campo, en vez de un «esquema inválido» del SII sin explicación.
 *  - Todo texto entra por {@see Xml} (escapado y apto para ISO-8859-1).
 *
 * Formato de los datos: las claves son los nombres de elemento del SII (`RUTEmisor`); los atributos van con «@» (`@version`); un elemento repetible recibe
 * una lista; un elemento con atributos y texto recibe `['#text' => ..., '@atributo' => ...]`.
 */
final class SpecXml
{
    /**
     * @param list<string> $deferred Elementos obligatorios que se agregan después (p. ej. el timbre `TED` o la firma): no se exigen aquí.
     * @param bool $truncate Si es true, un texto más largo que el máximo se recorta en vez de dar error.
     */
    public function __construct(
        private readonly string $namespace,
        private readonly array $deferred = [],
        private readonly bool $truncate = false,
    ) {
    }

    /**
     * @param array<string, mixed> $node Nodo de la especificación.
     * @param array<string, mixed>|scalar|null $value
     */
    public function element(DOMDocument $doc, array $node, mixed $value, string $path = ''): DOMElement
    {
        $name = (string) $node['name'];
        $path = $path === '' ? $name : $path;
        $el = $doc->createElementNS($this->namespace, $name);

        $children = $node['children'] ?? [];
        $data = [];
        $texto = null;
        if (is_array($value)) {
            $data = $value;
            if (array_key_exists('#text', $data)) {
                $texto = $data['#text'];
            }
        } elseif ($value !== null) {
            if ($children !== []) {
                throw new InvalidArgumentException("«{$name}» ({$path}) debe ser un conjunto de campos, no un valor suelto.");
            }
            $texto = $value;
        }

        $consumed = ['#text' => true];
        foreach ($node['attributes'] ?? [] as $attr) {
            $key = '@' . $attr['name'];
            $consumed[$key] = true;
            $val = $data[$key] ?? null;
            if ($val === null || $val === '') {
                if (($attr['fixed'] ?? null) !== null) {
                    $val = $attr['fixed'];
                } elseif ($attr['required']) {
                    throw new InvalidArgumentException("Falta el atributo requerido «{$attr['name']}» en {$path}.");
                } else {
                    continue;
                }
            }
            $el->setAttribute($attr['name'], $this->scalar($val, $attr['type'] ?? null, "{$path}/@{$attr['name']}"));
        }

        if ($children !== []) {
            $this->children($doc, $el, $children, $data, $path, $consumed);
        } elseif ($texto !== null && $texto !== '') {
            $el->appendChild($doc->createTextNode($this->scalar($texto, $node['type'] ?? null, $path)));
        }

        $extra = array_values(array_filter(array_keys($data), static fn ($k): bool => !isset($consumed[$k])));
        if ($extra !== []) {
            $validos = $children !== [] ? Spec::childNames($node) : [];
            throw new InvalidArgumentException(
                "El campo «{$extra[0]}» no existe en {$path}." . ($validos !== [] ? ' Campos válidos: ' . implode(', ', $validos) . '.' : '')
            );
        }

        return $el;
    }

    /**
     * @param list<array<string, mixed>> $items
     * @param array<string, mixed> $data
     * @param array<string, bool> $consumed
     */
    private function children(DOMDocument $doc, DOMElement $parent, array $items, array $data, string $path, array &$consumed): void
    {
        foreach ($items as $item) {
            if (isset($item['choice'])) {
                $this->choice($doc, $parent, $item, $data, $path, $consumed);
            } elseif (isset($item['sequence'])) {
                $this->children($doc, $parent, $item['sequence'], $data, $path, $consumed);
            } else {
                $this->member($doc, $parent, $item, $data, $path, $consumed);
            }
        }
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, mixed> $data
     * @param array<string, bool> $consumed
     */
    private function member(DOMDocument $doc, DOMElement $parent, array $item, array $data, string $path, array &$consumed): void
    {
        $name = (string) $item['name'];
        $consumed[$name] = true;
        if (isset($item['ref']) || $name === 'Signature') { // ds:Signature (referenciada o declarada en línea, como en LibroBoleta): la agrega la firma, no los datos
            if (array_key_exists($name, $data)) {
                throw new InvalidArgumentException("«{$name}» ({$path}) no se entrega como dato: lo agrega la firma.");
            }

            return;
        }

        $value = $data[$name] ?? null;
        $min = (int) $item['min'];
        $max = $item['max'];
        if ($value === null || $value === '' || $value === []) {
            if ($min >= 1 && !in_array($name, $this->deferred, true)) {
                throw new InvalidArgumentException("Falta el campo requerido «{$name}» en {$path}.");
            }

            return;
        }

        $esLista = is_array($value) && array_is_list($value);
        if ($esLista && $max === 1) {
            throw new InvalidArgumentException("«{$name}» ({$path}) admite un solo valor, no una lista.");
        }
        $lista = $esLista ? $value : [$value];
        if ($max !== null && count($lista) > $max) {
            throw new InvalidArgumentException("«{$name}» ({$path}) admite como máximo {$max} repeticiones y se entregaron " . count($lista) . '.');
        }
        foreach ($lista as $i => $v) {
            $sub = "{$path}/{$name}" . (count($lista) > 1 ? '[' . ($i + 1) . ']' : '');
            $parent->appendChild($this->element($doc, $item, $v, $sub));
        }
    }

    /**
     * @param array<string, mixed> $choice
     * @param array<string, mixed> $data
     * @param array<string, bool> $consumed
     */
    private function choice(DOMDocument $doc, DOMElement $parent, array $choice, array $data, string $path, array &$consumed): void
    {
        $alternativas = array_values(array_filter($choice['choice'], static fn (array $a): bool => isset($a['name'])));
        $nombres = array_map(static fn (array $a): string => (string) $a['name'], $alternativas);
        foreach ($nombres as $n) {
            $consumed[$n] = true;
        }
        $presentes = array_values(array_filter($alternativas, static fn (array $a): bool => isset($data[$a['name']]) && $data[$a['name']] !== '' && $data[$a['name']] !== []));

        if ($presentes === []) {
            if ((int) $choice['min'] >= 1 && !array_intersect($nombres, $this->deferred)) {
                throw new InvalidArgumentException("Debe indicar uno de: " . implode(', ', $nombres) . " en {$path}.");
            }

            return;
        }
        if (count($presentes) > 1 && ($choice['max'] ?? 1) === 1) {
            throw new InvalidArgumentException('Indique solo uno de: ' . implode(', ', $nombres) . " en {$path}.");
        }
        foreach ($presentes as $alt) {
            $this->member($doc, $parent, $alt, $data, $path, $consumed);
        }
    }

    /** @param array<string, mixed>|null $type Restricciones del esquema para este valor. */
    private function scalar(mixed $value, ?array $type, string $path): string
    {
        if (is_bool($value) || is_array($value) || is_object($value)) {
            throw new InvalidArgumentException("El valor de {$path} debe ser un texto o un número.");
        }
        $base = $type['base'] ?? 'string';
        $texto = is_float($value) ? $this->decimal($value, $type) : trim((string) $value);

        if (!in_array($base, ['base64Binary'], true)) {
            $texto = Xml::latin1($texto, $path);
        }
        if ($type === null) {
            return $texto;
        }

        $this->validar($base, $type, $texto, $path);

        return $texto;
    }

    /** @param array<string, mixed>|null $type */
    private function decimal(float $v, ?array $type): string
    {
        $dec = (int) ($type['fractionDigits'] ?? 6);
        $s = rtrim(rtrim(number_format($v, $dec, '.', ''), '0'), '.');

        return $s === '' || $s === '-0' ? '0' : $s;
    }

    /** @param array<string, mixed> $t */
    private function validar(string $base, array $t, string &$texto, string $path): void
    {
        $enteros = ['integer', 'int', 'long', 'unsignedLong', 'positiveInteger', 'nonNegativeInteger', 'short', 'byte'];
        if (in_array($base, $enteros, true)) {
            if (!preg_match('/^-?\d+$/', $texto)) {
                throw new InvalidArgumentException("{$path}: «{$texto}» no es un número entero.");
            }
            if ($base === 'positiveInteger' && (float) $texto < 1) {
                throw new InvalidArgumentException("{$path}: debe ser un entero positivo (recibido {$texto}).");
            }
            if (($base === 'nonNegativeInteger' || $base === 'unsignedLong') && (float) $texto < 0) {
                throw new InvalidArgumentException("{$path}: no puede ser negativo (recibido {$texto}).");
            }
            $this->rango($t, (float) $texto, $texto, $path);
        } elseif ($base === 'decimal') {
            if (!preg_match('/^-?\d+(\.\d+)?$/', $texto)) {
                throw new InvalidArgumentException("{$path}: «{$texto}» no es un número decimal.");
            }
            [$entera, $fraccion] = array_pad(explode('.', ltrim($texto, '-'), 2), 2, '');
            $fraccion = rtrim($fraccion, '0');
            if (isset($t['fractionDigits']) && strlen($fraccion) > (int) $t['fractionDigits']) {
                throw new InvalidArgumentException("{$path}: admite como máximo {$t['fractionDigits']} decimales («{$texto}»).");
            }
            if (isset($t['totalDigits']) && strlen(ltrim($entera, '0')) + strlen($fraccion) > (int) $t['totalDigits']) {
                throw new InvalidArgumentException("{$path}: admite como máximo {$t['totalDigits']} dígitos («{$texto}»).");
            }
            $this->rango($t, (float) $texto, $texto, $path);
        } elseif ($base === 'date') {
            if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $texto, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                throw new InvalidArgumentException("{$path}: «{$texto}» no es una fecha válida (formato AAAA-MM-DD).");
            }
            foreach (['minInclusive' => '<', 'maxInclusive' => '>'] as $k => $op) {
                if (isset($t[$k]) && (($op === '<' && $texto < $t[$k]) || ($op === '>' && $texto > $t[$k]))) {
                    throw new InvalidArgumentException("{$path}: la fecha {$texto} está fuera del rango permitido ({$t['minInclusive']} a {$t['maxInclusive']}).");
                }
            }
        } elseif ($base === 'dateTime') {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/', $texto)) {
                throw new InvalidArgumentException("{$path}: «{$texto}» no es una fecha y hora válida (formato AAAA-MM-DDTHH:MM:SS).");
            }
        } elseif ($base === 'base64Binary') {
            if (!preg_match('/^[A-Za-z0-9+\/=\s]*$/', $texto)) {
                throw new InvalidArgumentException("{$path}: no es base64 válido.");
            }
            return;
        }

        $this->texto($t, $texto, $path);
    }

    /** @param array<string, mixed> $t */
    private function rango(array $t, float $n, string $texto, string $path): void
    {
        foreach (['minInclusive' => fn ($v, $l) => $v < $l, 'maxInclusive' => fn ($v, $l) => $v > $l, 'minExclusive' => fn ($v, $l) => $v <= $l, 'maxExclusive' => fn ($v, $l) => $v >= $l] as $k => $falla) {
            if (isset($t[$k]) && is_numeric($t[$k]) && $falla($n, (float) $t[$k])) {
                throw new InvalidArgumentException("{$path}: {$texto} está fuera del rango permitido ({$k} {$t[$k]}).");
            }
        }
    }

    /** @param array<string, mixed> $t */
    private function texto(array $t, string &$texto, string $path): void
    {
        $largo = mb_strlen($texto);
        if (isset($t['maxLength']) && $largo > (int) $t['maxLength']) {
            if ($this->truncate) {
                $texto = mb_substr($texto, 0, (int) $t['maxLength']);
                $largo = mb_strlen($texto);
            } else {
                throw new InvalidArgumentException("{$path}: el texto tiene {$largo} caracteres y el máximo es {$t['maxLength']}.");
            }
        }
        if (isset($t['minLength']) && $largo < (int) $t['minLength']) {
            throw new InvalidArgumentException("{$path}: el texto debe tener al menos {$t['minLength']} caracteres.");
        }
        if (isset($t['length']) && $largo !== (int) $t['length']) {
            throw new InvalidArgumentException("{$path}: el texto debe tener exactamente {$t['length']} caracteres.");
        }
        if (isset($t['enum']) && !in_array($texto, $t['enum'], true)) {
            throw new InvalidArgumentException("{$path}: «{$texto}» no es un valor permitido (" . implode(', ', array_slice($t['enum'], 0, 12)) . (count($t['enum']) > 12 ? ', …' : '') . ').');
        }
        if (isset($t['pattern']) && @preg_match('/^(?:' . str_replace('/', '\/', $t['pattern']) . ')$/u', $texto) === 0) {
            throw new InvalidArgumentException("{$path}: «{$texto}» no tiene el formato esperado.");
        }
    }
}
