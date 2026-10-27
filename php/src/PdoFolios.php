<?php

declare(strict_types=1);

namespace IngeInnova\DteChile;

use InvalidArgumentException;
use PDO;
use PDOException;
use RuntimeException;

/**
 * Folios en su propia base de datos (MySQL, MariaDB, PostgreSQL, SQLite...), sin bloqueos largos: cada folio se reserva con una actualización
 * condicional («compare-and-swap»), así dos procesos o servidores nunca reciben el mismo folio. Crea la tabla si no existe.
 */
final class PdoFolios implements FolioStore
{
    public function __construct(private readonly PDO $pdo, private readonly string $tabla = 'dte_folios', private readonly int $intentos = 25)
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $tabla)) {
            throw new InvalidArgumentException('El nombre de la tabla de folios solo admite letras, números y «_».');
        }
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS {$tabla} (tipo INT NOT NULL PRIMARY KEY, ultimo INT NOT NULL)");
    }

    public function siguiente(int $tipo, int $desde, int $hasta): int
    {
        for ($i = 0; $i < $this->intentos; $i++) {
            $sel = $this->pdo->prepare("SELECT ultimo FROM {$this->tabla} WHERE tipo = ?");
            $sel->execute([$tipo]);
            $fila = $sel->fetchColumn();
            $actual = $fila === false ? null : (int) $fila;
            $n = max($actual ?? 0, $desde - 1) + 1;
            if ($n > $hasta) {
                throw new RuntimeException("Se agotaron los folios del tipo {$tipo} (rango {$desde}-{$hasta}). Solicite un CAF nuevo al SII.");
            }
            try {
                if ($actual === null) {
                    $this->pdo->prepare("INSERT INTO {$this->tabla} (tipo, ultimo) VALUES (?, ?)")->execute([$tipo, $n]);

                    return $n;
                }
                $upd = $this->pdo->prepare("UPDATE {$this->tabla} SET ultimo = ? WHERE tipo = ? AND ultimo = ?");
                $upd->execute([$n, $tipo, $actual]);
                if ($upd->rowCount() === 1) {
                    return $n;
                }
            } catch (PDOException) {
                // otro proceso insertó la fila al mismo tiempo: se vuelve a leer
            }
        }

        throw new RuntimeException('No fue posible reservar un folio: la tabla de folios está muy disputada. Reintente.');
    }
}
