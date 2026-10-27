<?php

declare(strict_types=1);

/** Carga un archivo .env sencillo (CLAVE=valor) a las variables de entorno, sin pisar las que ya existen. Nunca imprime los valores. */
function cargarEnv(string $ruta): void
{
    if (!is_file($ruta)) {
        fwrite(STDERR, "No existe el archivo de configuración: {$ruta}\n");
        exit(2);
    }
    $permisos = fileperms($ruta) & 0o077;
    if ($permisos !== 0) {
        fwrite(STDERR, "Aviso: {$ruta} es legible por otros usuarios. Ejecute: chmod 600 \"{$ruta}\"\n");
    }
    foreach (file($ruta, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $linea) {
        if (str_starts_with(trim($linea), '#') || !str_contains($linea, '=')) {
            continue;
        }
        [$k, $v] = explode('=', $linea, 2);
        $v = trim($v);
        if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && $v[-1] === $v[0]) {
            $v = substr($v, 1, -1);
        }
        if (getenv(trim($k)) === false) {
            putenv(trim($k) . '=' . $v);
        }
    }
}
