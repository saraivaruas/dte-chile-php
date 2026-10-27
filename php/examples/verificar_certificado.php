<?php

declare(strict_types=1);

/**
 * Comprueba un certificado digital SIN enviar nada a nadie: que abra con la clave, el RUT del titular, el emisor del certificado y la vigencia.
 *   php php/examples/verificar_certificado.php ~/.config/dte-chile/.env
 * No imprime la clave ni ningún dato privado.
 */
require __DIR__ . '/_env.php';
require __DIR__ . '/_comun.php';

cargarEnv($argv[1] ?? (getenv('HOME') . '/.config/dte-chile/.env'));
$cert = \IngeInnova\DteChile\Certificate::fromPkcs12((string) file_get_contents(entorno('DTE_PFX')), entorno('DTE_PFX_CLAVE'));
$info = openssl_x509_parse($cert->certificatePem());
$vence = $cert->notAfter();
$dias = (int) (new DateTimeImmutable('now'))->diff($vence)->format('%r%a');

echo "Certificado abre correctamente con la clave.\n";
echo 'RUT del titular: ' . ($cert->subjectRut() ?? '(no encontrado en serialNumber)') . "\n";
echo 'Titular: ' . ($info['subject']['CN'] ?? '?') . "\n";
echo 'Emitido por: ' . ($info['issuer']['O'] ?? $info['issuer']['CN'] ?? '?') . "\n";
echo 'Vence: ' . $vence->format('Y-m-d') . ($dias < 0 ? ' (VENCIDO)' : " (en {$dias} días)") . "\n";
