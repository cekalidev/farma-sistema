<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Ejecuta N procesos hijos (pcntl_fork) que arrancan su trabajo A LA VEZ, cada uno con su propia
 * conexión a PostgreSQL. Barrera en dos fases:
 *
 *  1. Cada hijo conecta (purge + reconnect + getPdo), ejecuta su preparación y avisa "listo-{i}"
 *     por un socket compartido; luego espera la barrera (LOCK_SH sobre un archivo que el padre
 *     tiene con LOCK_EX).
 *  2. El padre espera (con timeout) a tener los N avisos y SOLO entonces suelta la barrera. Así el
 *     solapamiento no depende de cuánto tarde el último hijo en conectarse.
 *
 * El padre suelta la barrera siempre (finally), aunque falle un fork o un hijo: en ese caso marca
 * "abortar" antes y los hijos salen sin ejecutar el trabajo. Luego recoge a todos con
 * pcntl_waitpid y exige salida 0 de cada uno.
 *
 * Los hijos terminan con pcntl_exec (reemplazo de la imagen del proceso): no ejecutan los
 * shutdown handlers ni destructores heredados de Pest/PHPUnit/Laravel. El resultado ya está
 * escrito, volcado y cerrado antes.
 */
final class Concurrencia
{
    private const SALIDA_OK = 0;

    private const SALIDA_ERROR = 1;

    private const SALIDA_ABORTADO = 2;

    /**
     * @param  int  $procesos  número de hijos (≥ 1); el índice de cada uno va de 0 a N−1
     * @param  callable(int): string  $trabajo  se ejecuta tras la barrera; devuelve el resultado serializado
     * @param  (callable(int): void)|null  $preparar  se ejecuta tras conectar y antes de avisar "listo"
     * @return list<string> resultado de cada hijo, en orden de índice
     */
    public static function enParalelo(
        int $procesos,
        callable $trabajo,
        ?callable $preparar = null,
        int $segundosHastaListos = 30,
        int $segundosHastaFin = 120,
    ): array {
        if (! function_exists('pcntl_fork')) {
            throw new RuntimeException('Concurrencia: instala la extensión pcntl para ejecutar este test.');
        }

        if ($procesos < 1) {
            throw new RuntimeException('Concurrencia: se necesita al menos un proceso.');
        }

        $dir = sys_get_temp_dir().'/farma-concurrencia-'.bin2hex(random_bytes(6));
        mkdir($dir);
        $barrera = "{$dir}/barrera";
        touch($barrera);

        $cerrojo = fopen($barrera, 'r');
        if ($cerrojo === false || ! flock($cerrojo, LOCK_EX)) {
            throw new RuntimeException('Concurrencia: no se pudo tomar la barrera.');
        }

        // Canal de avisos hijo → padre. $lectura queda en el padre; $escritura la heredan los hijos.
        $par = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($par === false) {
            throw new RuntimeException('Concurrencia: no se pudo crear el canal de avisos.');
        }
        [$lectura, $escritura] = $par;

        // Los hijos no deben heredar el socket de PostgreSQL del padre.
        DB::disconnect();

        /** @var array<int, int> $pids índice → pid */
        $pids = [];
        $fallo = null;
        $avisos = [];

        try {
            for ($i = 0; $i < $procesos; $i++) {
                $pid = pcntl_fork();
                if ($pid === -1) {
                    throw new RuntimeException("Concurrencia: pcntl_fork falló al lanzar el hijo {$i}.");
                }

                if ($pid === 0) {
                    self::hijo($i, $dir, $cerrojo, $lectura, $escritura, $trabajo, $preparar);
                }

                $pids[$i] = $pid;
            }

            // El padre no escribe: al cerrar su copia, EOF llega cuando terminan todos los hijos.
            fclose($escritura);
            $escritura = null;

            self::esperarListos($lectura, $procesos, $segundosHastaListos, $avisos);
        } catch (Throwable $e) {
            $fallo = $e;
        } finally {
            if ($escritura !== null) {
                fclose($escritura);
            }

            if ($fallo !== null) {
                // Los hijos que pasen la barrera verán esta marca y saldrán sin hacer el trabajo.
                touch("{$dir}/abortar");
            }

            flock($cerrojo, LOCK_UN);
            fclose($cerrojo);

            $estados = self::recoger($lectura, $pids, $segundosHastaFin);
            fclose($lectura);

            DB::purge();
            DB::reconnect();
        }

        try {
            if ($fallo !== null) {
                throw new RuntimeException(
                    'Concurrencia: '.$fallo->getMessage().self::describirHijos($dir, $pids, $estados, $avisos),
                    0,
                    $fallo,
                );
            }

            $resultados = [];
            $problemas = [];
            foreach ($pids as $i => $pid) {
                $estado = $estados[$pid] ?? null;
                $archivo = "{$dir}/resultado-{$i}";
                if ($estado === null || ! pcntl_wifexited($estado) || pcntl_wexitstatus($estado) !== self::SALIDA_OK || ! is_file($archivo)) {
                    $problemas[] = $i;

                    continue;
                }

                $resultados[] = (string) file_get_contents($archivo);
            }

            if ($problemas !== []) {
                throw new RuntimeException(
                    'Concurrencia: hijos sin resultado válido ('.implode(', ', $problemas).').'
                    .self::describirHijos($dir, $pids, $estados, $avisos),
                );
            }

            return $resultados;
        } finally {
            foreach (glob("{$dir}/*") ?: [] as $archivo) {
                @unlink($archivo);
            }
            @rmdir($dir);
        }
    }

    /**
     * Cuerpo del hijo. Nunca retorna: termina con self::terminar().
     *
     * @param  resource  $cerrojoHeredado
     * @param  resource  $lectura
     * @param  resource  $escritura
     * @param  callable(int): string  $trabajo
     * @param  (callable(int): void)|null  $preparar
     */
    private static function hijo(int $i, string $dir, $cerrojoHeredado, $lectura, $escritura, callable $trabajo, ?callable $preparar): never
    {
        $codigo = self::SALIDA_ERROR;
        $listo = false;

        try {
            // Descriptores heredados que el hijo no usa. Cerrar el del cerrojo NO suelta el LOCK_EX
            // del padre (el padre conserva su descriptor abierto sobre la misma descripción).
            fclose($cerrojoHeredado);
            fclose($lectura);

            DB::purge();
            DB::reconnect();
            DB::connection()->getPdo();

            if ($preparar !== null) {
                $preparar($i);
            }

            fwrite($escritura, "listo-{$i}\n");
            fflush($escritura);
            $listo = true;

            // Nueva descripción de archivo: el LOCK_SH bloquea hasta que el padre suelte el LOCK_EX.
            $espera = fopen("{$dir}/barrera", 'r');
            if ($espera === false || ! flock($espera, LOCK_SH)) {
                throw new RuntimeException('no se pudo esperar la barrera');
            }

            if (is_file("{$dir}/abortar")) {
                $codigo = self::SALIDA_ABORTADO;
            } else {
                $resultado = $trabajo($i);
                self::escribir("{$dir}/resultado-{$i}", $resultado);
                $codigo = self::SALIDA_OK;
            }
        } catch (Throwable $e) {
            self::escribir("{$dir}/error-{$i}", $e::class.': '.$e->getMessage());
            if (! $listo) {
                // Avisa al padre para que no espere hasta el timeout.
                @fwrite($escritura, "fallo-{$i}\n");
                @fflush($escritura);
            }
        }

        try {
            DB::disconnect();
        } catch (Throwable) {
            // El proceso termina igual; la conexión la cierra el sistema.
        }

        self::terminar($codigo);
    }

    /**
     * Escribe el archivo y lo cierra (volcado incluido) antes de que el hijo termine.
     */
    private static function escribir(string $ruta, string $contenido): void
    {
        $f = fopen($ruta, 'w');
        if ($f === false) {
            throw new RuntimeException("no se pudo abrir {$ruta}");
        }
        fwrite($f, $contenido);
        fflush($f);
        fclose($f);
    }

    /**
     * Termina el hijo sin ejecutar shutdown handlers ni destructores heredados.
     */
    private static function terminar(int $codigo): never
    {
        @pcntl_exec('/bin/sh', ['-c', 'exit '.$codigo]);

        // pcntl_exec solo vuelve si falló: SIGKILL tampoco ejecuta nada de PHP.
        posix_kill(posix_getpid(), SIGKILL);

        exit($codigo); // inalcanzable
    }

    /**
     * Espera los N avisos "listo-{i}". Falla si un hijo avisa "fallo-{i}", si el canal se cierra
     * antes de tiempo o si se agota el timeout.
     *
     * @param  resource  $lectura
     * @param  array<int, string>  $avisos  índice → aviso recibido (se rellena aunque falle)
     */
    private static function esperarListos($lectura, int $procesos, int $segundos, array &$avisos): void
    {
        $limite = microtime(true) + $segundos;
        $buffer = '';

        while (count($avisos) < $procesos) {
            $restante = $limite - microtime(true);
            if ($restante <= 0) {
                throw new RuntimeException(sprintf(
                    'timeout de %d s esperando a que los hijos estén listos (%d de %d).',
                    $segundos,
                    count(array_filter($avisos, fn (string $a): bool => $a === 'listo')),
                    $procesos,
                ));
            }

            $leer = [$lectura];
            $nada = null;
            $otro = null;
            $seg = (int) floor($restante);
            $listos = @stream_select($leer, $nada, $otro, $seg, (int) (($restante - $seg) * 1_000_000));
            if ($listos === false) {
                continue; // interrumpido por una señal (p. ej. SIGCHLD): reintenta hasta el límite
            }
            if ($listos === 0) {
                continue;
            }

            $trozo = fread($lectura, 8192);
            if ($trozo === false || ($trozo === '' && feof($lectura))) {
                throw new RuntimeException('el canal de avisos se cerró antes de que todos los hijos estuvieran listos.');
            }
            $buffer .= $trozo;

            while (($fin = strpos($buffer, "\n")) !== false) {
                $linea = substr($buffer, 0, $fin);
                $buffer = substr($buffer, $fin + 1);
                if (preg_match('/^(listo|fallo)-(\d+)$/', $linea, $m) === 1) {
                    $avisos[(int) $m[2]] = $m[1];
                    if ($m[1] === 'fallo') {
                        throw new RuntimeException("el hijo {$m[2]} falló antes de llegar a la barrera.");
                    }
                }
            }
        }
    }

    /**
     * Espera a que terminen todos los hijos (EOF del canal, con timeout; si se agota, SIGKILL) y
     * los recoge con pcntl_waitpid.
     *
     * @param  resource  $lectura
     * @param  array<int, int>  $pids
     * @return array<int, int> pid → estado de pcntl_waitpid
     */
    private static function recoger($lectura, array $pids, int $segundos): array
    {
        $limite = microtime(true) + $segundos;

        while (! feof($lectura)) {
            $restante = $limite - microtime(true);
            if ($restante <= 0) {
                foreach ($pids as $pid) {
                    @posix_kill($pid, SIGKILL);
                }
                break;
            }

            $leer = [$lectura];
            $nada = null;
            $otro = null;
            $seg = (int) floor($restante);
            if (@stream_select($leer, $nada, $otro, $seg, (int) (($restante - $seg) * 1_000_000)) > 0) {
                fread($lectura, 8192);
            }
        }

        $estados = [];
        foreach ($pids as $pid) {
            $estado = 0;
            pcntl_waitpid($pid, $estado);
            $estados[$pid] = $estado;
        }

        return $estados;
    }

    /**
     * @param  array<int, int>  $pids
     * @param  array<int, int>  $estados
     * @param  array<int, string>  $avisos
     */
    private static function describirHijos(string $dir, array $pids, array $estados, array $avisos): string
    {
        $lineas = [];
        foreach ($pids as $i => $pid) {
            $estado = $estados[$pid] ?? null;
            $salida = match (true) {
                $estado === null => 'sin recoger',
                pcntl_wifexited($estado) => 'salida '.pcntl_wexitstatus($estado),
                pcntl_wifsignaled($estado) => 'señal '.pcntl_wtermsig($estado),
                default => 'estado '.$estado,
            };
            $error = is_file("{$dir}/error-{$i}") ? ' — '.file_get_contents("{$dir}/error-{$i}") : '';
            $lineas[] = "hijo {$i}: ".($avisos[$i] ?? 'sin aviso').", {$salida}{$error}";
        }

        return $lineas === [] ? '' : "\n".implode("\n", $lineas);
    }
}
