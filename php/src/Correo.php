<?php

declare(strict_types=1);

namespace IngeInnova\DteChile;

use InvalidArgumentException;
use RuntimeException;

/** Arma y envía correos con adjuntos (por ejemplo el XML y el PDF de un documento) por SMTP, sin dependencias. */
final class Correo
{
    /**
     * @param string[] $para
     * @param array<int, array{nombre: string, tipo: string, contenido: string}> $adjuntos
     * @return string Mensaje completo (cabeceras y cuerpo) listo para el comando DATA.
     */
    public static function mensaje(ConfigSmtp $cfg, array $para, string $asunto, string $texto, array $adjuntos = []): string
    {
        if ($para === []) {
            throw new InvalidArgumentException('Falta al menos un destinatario.');
        }
        foreach ($para as $p) {
            if (!filter_var($p, FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException("Correo destinatario no válido: {$p}");
            }
        }
        $limite = '=_dte_' . bin2hex(random_bytes(12));
        $de = $cfg->nombreRemitente !== '' ? self::cabeceraTexto($cfg->nombreRemitente) . " <{$cfg->remitente}>" : $cfg->remitente;
        $m = "From: {$de}\r\nTo: " . implode(', ', $para) . "\r\nSubject: " . self::cabeceraTexto($asunto) . "\r\nDate: " . gmdate('D, d M Y H:i:s') . " +0000\r\n"
            . "Message-ID: <" . bin2hex(random_bytes(10)) . '@' . (explode('@', $cfg->remitente)[1] ?? 'localhost') . ">\r\nMIME-Version: 1.0\r\n"
            . "Content-Type: multipart/mixed; boundary=\"{$limite}\"\r\n\r\n";
        $m .= "--{$limite}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($texto), 76, "\r\n");
        foreach ($adjuntos as $a) {
            $nombre = str_replace(['"', "\r", "\n"], '', $a['nombre']);
            $m .= "--{$limite}\r\nContent-Type: {$a['tipo']}; name=\"{$nombre}\"\r\nContent-Transfer-Encoding: base64\r\n"
                . "Content-Disposition: attachment; filename=\"{$nombre}\"\r\n\r\n" . chunk_split(base64_encode($a['contenido']), 76, "\r\n");
        }

        return $m . "--{$limite}--\r\n";
    }

    private static function cabeceraTexto(string $t): string
    {
        return preg_match('/^[\x20-\x7E]*$/', $t) ? $t : '=?UTF-8?B?' . base64_encode($t) . '?=';
    }

    /**
     * Envía un mensaje por SMTP. `$flujo` permite entregar una conexión ya abierta (pruebas); si es null se conecta al servidor de la configuración.
     *
     * @param string[] $para
     * @param resource|null $flujo
     */
    public static function enviar(ConfigSmtp $cfg, array $para, string $mensaje, $flujo = null): void
    {
        $f = $flujo ?? self::conectar($cfg);
        $leer = static function () use ($f): array {
            $codigo = '';
            do {
                $linea = fgets($f, 2048);
                if ($linea === false) {
                    throw new RuntimeException('El servidor de correo cerró la conexión.');
                }
                $codigo = substr($linea, 0, 3);
            } while (isset($linea[3]) && $linea[3] === '-');

            return [(int) $codigo, trim($linea)];
        };
        $cmd = static function (string $c, array $ok) use ($f, $leer): string {
            fwrite($f, $c . "\r\n");
            [$cod, $txt] = $leer();
            if (!in_array($cod, $ok, true)) {
                throw new RuntimeException("El servidor de correo respondió {$cod} a «" . (str_starts_with($c, 'AUTH') || strlen($c) > 60 ? substr($c, 0, 10) . '…' : $c) . "»: {$txt}");
            }

            return $txt;
        };
        [$cod, $txt] = $leer();
        if ($cod !== 220) {
            throw new RuntimeException("El servidor de correo no aceptó la conexión: {$txt}");
        }
        $host = gethostname() ?: 'localhost';
        $cmd("EHLO {$host}", [250]);
        if ($cfg->cifrado === 'tls' && $flujo === null) {
            $cmd('STARTTLS', [220]);
            if (!stream_socket_enable_crypto($f, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('No fue posible iniciar el cifrado TLS con el servidor de correo.');
            }
            $cmd("EHLO {$host}", [250]);
        }
        if ($cfg->usuario !== null) {
            $cmd('AUTH LOGIN', [334]);
            $cmd(base64_encode($cfg->usuario), [334]);
            $cmd(base64_encode((string) $cfg->clave), [235]);
        }
        $cmd("MAIL FROM:<{$cfg->remitente}>", [250]);
        foreach ($para as $p) {
            $cmd("RCPT TO:<{$p}>", [250, 251]);
        }
        $cmd('DATA', [354]);
        $cuerpo = preg_replace('/^\./m', '..', str_replace("\r\n", "\n", $mensaje)) ?? $mensaje;
        fwrite($f, str_replace("\n", "\r\n", $cuerpo) . "\r\n.\r\n");
        [$cod, $txt] = $leer();
        if ($cod !== 250) {
            throw new RuntimeException("El servidor de correo no aceptó el mensaje ({$cod}): {$txt}");
        }
        $cmd('QUIT', [221]);
        if ($flujo === null) {
            fclose($f);
        }
    }

    /** @return resource */
    private static function conectar(ConfigSmtp $cfg)
    {
        $destino = ($cfg->cifrado === 'ssl' ? 'ssl://' : 'tcp://') . $cfg->servidor . ':' . $cfg->puerto;
        $f = @stream_socket_client($destino, $codigo, $texto, $cfg->timeout);
        if ($f === false) {
            throw new RuntimeException("No fue posible conectar con el servidor de correo {$cfg->servidor}:{$cfg->puerto} ({$texto})");
        }
        stream_set_timeout($f, $cfg->timeout);

        return $f;
    }
}
