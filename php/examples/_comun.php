<?php

declare(strict_types=1);

/**
 * Configuración común de los ejemplos. Todo sale de variables de entorno: nada de secretos en el código ni en el historial.
 *
 *   DTE_PFX           ruta al certificado digital (.pfx / .p12)
 *   DTE_PFX_CLAVE     clave del certificado
 *   DTE_EMISOR_RUT, DTE_EMISOR_RAZON, DTE_EMISOR_GIRO, DTE_EMISOR_ACTECO   datos de la empresa emisora
 *   DTE_CAF_DIR       carpeta con los CAF (archivos .xml descargados del SII)
 *   DTE_FOLIOS        archivo JSON donde se guarda el avance de folios (por defecto ./folios.json)
 *   DTE_RES_FECHA, DTE_RES_NUMERO   resolución del SII (por defecto 2026-01-01 y 0)
 *   DTE_AMBIENTE      "certificacion" (por defecto) o "produccion"
 */

use IngeInnova\DteChile\{ArchivoFolios, Caf, Certificate, Emisor, Facturador, Receptor, SiiClient};

spl_autoload_register(static function (string $c): void {
    if (str_starts_with($c, 'IngeInnova\\DteChile\\')) {
        require __DIR__ . '/../src/' . str_replace('\\', '/', substr($c, 20)) . '.php';
    }
});

function entorno(string $nombre, ?string $defecto = null): string
{
    $v = getenv($nombre);
    if ($v === false || $v === '') {
        if ($defecto !== null) {
            return $defecto;
        }
        fwrite(STDERR, "Falta la variable de entorno {$nombre}. Vea php/examples/_comun.php.\n");
        exit(2);
    }

    return $v;
}

function crearFacturador(): Facturador
{
    $cert = Certificate::fromPkcs12((string) file_get_contents(entorno('DTE_PFX')), entorno('DTE_PFX_CLAVE'));
    $emisor = new Emisor(entorno('DTE_EMISOR_RUT'), entorno('DTE_EMISOR_RAZON'), entorno('DTE_EMISOR_GIRO'), (int) entorno('DTE_EMISOR_ACTECO'));
    $ambiente = entorno('DTE_AMBIENTE', 'certificacion') === 'produccion' ? SiiClient::PRODUCCION : SiiClient::CERTIFICACION;
    $f = new Facturador(
        $emisor, $cert, new ArchivoFolios(entorno('DTE_FOLIOS', getcwd() . '/folios.json')), $ambiente,
        ['fecha' => entorno('DTE_RES_FECHA', '2026-01-01'), 'numero' => (int) entorno('DTE_RES_NUMERO', '0')],
    );
    foreach (glob(rtrim(entorno('DTE_CAF_DIR'), '/') . '/*.xml') ?: [] as $archivo) {
        $f->agregarCaf(Caf::fromXml((string) file_get_contents($archivo)));
    }

    return $f;
}

function clienteEjemplo(): Receptor
{
    return new Receptor('66666666-6', 'Cliente de Ejemplo', 'Comercio', 'Calle Falsa 456', 'Las Condes', 'Santiago');
}

/** Guarda el documento en ./salida y, solo si se pasa --enviar, lo sube al SII. */
function guardarYEnviar(Facturador $f, array $documentos, array $argv): void
{
    @mkdir('salida');
    foreach ($documentos as $d) {
        $ruta = "salida/{$d->tipo}-{$d->folio}.xml";
        file_put_contents($ruta, $d->toXml());
        echo "Documento {$d->tipo} folio {$d->folio} total \${$d->montoTotal} -> {$ruta}\n";
    }
    if (in_array('--enviar', $argv, true)) {
        $r = $f->enviar($documentos);
        echo "Enviado al SII. trackId: {$r->trackId}\n";
    } else {
        echo "(No se envió nada al SII. Agregue --enviar para subirlo.)\n";
    }
}
