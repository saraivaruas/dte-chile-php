<?php

declare(strict_types=1);

namespace IngeInnova\DteChile\Tests;

use IngeInnova\DteChile\ConfigSmtp;
use IngeInnova\DteChile\Correo;
use IngeInnova\DteChile\Linea;
use IngeInnova\DteChile\PdoFolios;

function jpegDePrueba(): string
{
    if (function_exists('imagecreatetruecolor')) {
        $im = imagecreatetruecolor(120, 60);
        imagefill($im, 0, 0, imagecolorallocate($im, 20, 90, 160));
        ob_start();
        imagejpeg($im, null, 80);

        return (string) ob_get_clean();
    }

    return (string) base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=');
}

prueba('PdoFolios: reserva folios consecutivos por tipo, persiste entre instancias y avisa cuando se agotan', function (): void {
    $pdo = new \PDO('sqlite::memory:');
    $a = new PdoFolios($pdo);
    igual([5, 6], [$a->siguiente(33, 5, 8), $a->siguiente(33, 5, 8)], 'consecutivos desde el inicio del rango');
    igual(1, $a->siguiente(61, 1, 3), 'cada tipo lleva su cuenta');
    igual(7, (new PdoFolios($pdo))->siguiente(33, 5, 8), 'otra instancia sobre la misma base continúa');
    igual(8, $a->siguiente(33, 5, 8), 'último folio del rango');
    lanza(fn () => $a->siguiente(33, 5, 8), 'Se agotaron los folios');
    igual(20, (new PdoFolios($pdo))->siguiente(33, 20, 30), 'un CAF nuevo con rango mayor salta al nuevo inicio');
    lanza(fn () => new PdoFolios($pdo, 'dte; DROP TABLE x'), 'nombre de la tabla');
});
prueba('PdoFolios: dos conexiones a la misma base nunca entregan el mismo folio', function (): void {
    $archivo = sys_get_temp_dir() . '/folios_' . bin2hex(random_bytes(4)) . '.sqlite';
    $a = new PdoFolios(new \PDO("sqlite:{$archivo}"));
    $b = new PdoFolios(new \PDO("sqlite:{$archivo}"));
    $vistos = [];
    for ($i = 0; $i < 20; $i++) {
        $vistos[] = ($i % 2 === 0 ? $a : $b)->siguiente(33, 1, 100);
    }
    igual(20, count(array_unique($vistos)), 'sin repetidos');
    igual(range(1, 20), $vistos, 'en orden');
    unlink($archivo);
});
prueba('PDF: el logotipo JPEG se incrusta sin deformarse y el PDF sigue siendo válido', function (): void {
    [$f] = facturador([33]);
    $doc = $f->factura(cliente(), [new Linea('Servicio', 1, 1000)], ['fechaEmision' => '2026-09-08']);
    $pdf = $f->pdf($doc, ['logo' => jpegDePrueba()]);
    verificaPdf($pdf);
    foreach (['/Subtype /Image', '/Filter /DCTDecode', '/XObject <<', ' Do Q'] as $t) {
        afirma(str_contains($pdf, $t), "Falta «{$t}» en el PDF.");
    }
    afirma(!str_contains($f->pdf($doc), '/XObject'), 'Sin logotipo no debe haber imágenes.');
    $ruta = sys_get_temp_dir() . '/logo_' . bin2hex(random_bytes(4)) . '.jpg';
    file_put_contents($ruta, jpegDePrueba());
    afirma(str_contains($f->pdf($doc, ['logo' => $ruta]), '/DCTDecode'), 'El logotipo también se puede entregar como ruta.');
    unlink($ruta);
    lanza(fn () => $f->pdf($doc, ['logo' => 'no es una imagen']), 'JPEG');
});
prueba('Correo: el mensaje lleva cabeceras MIME, el texto y los adjuntos en base64 y valida los destinatarios', function (): void {
    $cfg = new ConfigSmtp('smtp.ejemplo.cl', 587, 'facturacion@ejemplo.cl', 'usuario', 'clave', 'tls', 'Inge Innova SpA');
    $m = Correo::mensaje($cfg, ['cliente@ejemplo.cl'], 'Factura N° 1', "Hola\n.punto al inicio", [['nombre' => 'f.xml', 'tipo' => 'application/xml', 'contenido' => '<a>ñ</a>']]);
    foreach (['From: Inge Innova SpA <facturacion@ejemplo.cl>', 'To: cliente@ejemplo.cl', 'Subject: =?UTF-8?B?', 'multipart/mixed', 'filename="f.xml"', base64_encode('<a>ñ</a>')] as $t) {
        afirma(str_contains($m, $t), "Falta «{$t}».");
    }
    lanza(fn () => Correo::mensaje($cfg, ['no-es-correo'], 'x', 'y'), 'no válido');
    lanza(fn () => Correo::mensaje($cfg, [], 'x', 'y'), 'destinatario');
    lanza(fn () => new ConfigSmtp('s', 25, 'malo'), 'remitente');
});
prueba('Facturador::enviarPorCorreo conversa con el servidor SMTP y adjunta el XML y el PDF', function (): void {
    [$f] = facturador([33]);
    $doc = $f->factura(cliente(), [new Linea('Servicio', 1, 1000)], ['fechaEmision' => '2026-09-08']);
    [$cliente, $servidor] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    fwrite($servidor, "220 hola\r\n250-smtp.ejemplo.cl\r\n250 AUTH LOGIN\r\n334 VXNlcg==\r\n334 UGFzcw==\r\n235 ok\r\n250 ok\r\n250 ok\r\n354 siga\r\n250 recibido\r\n221 chao\r\n");
    $cfg = new ConfigSmtp('smtp.ejemplo.cl', 587, 'facturacion@ejemplo.cl', 'usuario', 'clave', 'tls');
    $f->enviarPorCorreo($doc, 'cliente@ejemplo.cl', $cfg, ['flujo' => $cliente]);
    stream_set_blocking($servidor, false);
    $enviado = (string) stream_get_contents($servidor);
    foreach (['EHLO', 'AUTH LOGIN', base64_encode('usuario'), base64_encode('clave'), 'MAIL FROM:<facturacion@ejemplo.cl>', 'RCPT TO:<cliente@ejemplo.cl>', 'DATA', "\r\n.\r\n", 'QUIT', 'filename="33-1.xml"', 'filename="33-1.pdf"', 'Subject: =?UTF-8?B?'] as $t) {
        afirma(str_contains($enviado, $t), "El diálogo SMTP no contiene «{$t}».");
    }
    afirma(!str_contains($enviado, 'STARTTLS'), 'Con una conexión ya abierta no debe pedir STARTTLS.');
    [$c2, $s2] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    fwrite($s2, "220 hola\r\n250 ok\r\n535 credenciales inválidas\r\n");
    lanza(fn () => $f->enviarPorCorreo($doc, 'cliente@ejemplo.cl', $cfg, ['flujo' => $c2, 'conPdf' => false]), '535');
});
