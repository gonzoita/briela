<?php

namespace App\Services\Automatizaciones;

use App\Models\AgenteInterno;
use App\Models\User;
use App\Support\Modulos;
use App\Support\Permisos;

/**
 * El grafo de una automatización: cómo se valida y cómo se recorre.
 *
 * ```
 * { "nodos":   [ {"id":"n1","tipo":"disparador","config":{"tipo":"evento","evento":"cotizacion.estado"}},
 *                {"id":"n2","tipo":"condicion","config":{"combinador":"y","reglas":[{"campo":"estado","operador":"igual","valor":"enviada"}]}},
 *                {"id":"n3","tipo":"espera","config":{"cantidad":5,"unidad":"dias"}},
 *                {"id":"n4","tipo":"accion","accion":"notificar","config":{…}} ],
 *   "aristas": [ {"de":"n1","a":"n2"}, {"de":"n2","a":"n3","rama":"si"}, {"de":"n3","a":"n4"} ] }
 * ```
 *
 * El motor admite ramas (`si` / `no`) desde el día uno; la pantalla en lista solo arma cadenas, y el
 * lienzo que venga después podrá dibujar ramas sin cambiar nada de esto.
 */
class GrafoAutomatizacion
{
    public const MAX_NODOS = 30;

    public const TIPOS = ['disparador', 'condicion', 'accion', 'espera'];

    /** Lo más que una espera puede durar: más que eso es un olvido, no una espera. */
    public const MAX_ESPERA_MINUTOS = 90 * 1440;

    // ─── Recorrer ────────────────────────────────────────────────────────────

    public static function nodo(array $grafo, ?string $id): ?array
    {
        return collect($grafo['nodos'] ?? [])->firstWhere('id', $id);
    }

    public static function primero(array $grafo): ?string
    {
        return collect($grafo['nodos'] ?? [])->firstWhere('tipo', 'disparador')['id'] ?? null;
    }

    /** El nodo que sigue a otro. Una condición elige su rama; el resto sigue la única salida. */
    public static function siguiente(array $grafo, string $desde, ?string $rama = null): ?string
    {
        foreach ($grafo['aristas'] ?? [] as $a) {
            if (($a['de'] ?? null) === $desde && ($a['rama'] ?? null) === $rama) {
                return $a['a'] ?? null;
            }
        }

        return null;
    }

    /** @return array{tipo: string, clave: ?string} */
    public static function disparadorDe(array $grafo): array
    {
        $cfg = self::nodo($grafo, self::primero($grafo))['config'] ?? [];

        return ['tipo' => (string) ($cfg['tipo'] ?? 'manual'), 'clave' => ($cfg['tipo'] ?? null) === 'evento' ? ($cfg['evento'] ?? null) : null];
    }

    // ─── Validar ─────────────────────────────────────────────────────────────

    /**
     * Todo lo que está mal en un grafo, en frases para una persona. Vacío = se puede guardar.
     *
     * Revisa también que **quien la arma pueda hacer lo que pide**: una automatización actúa con
     * los permisos de su autor, y poder armarlas no debe ser una puerta lateral a acciones que su
     * pantalla no le deja hacer.
     *
     * @return list<string>
     */
    public static function validar(array $grafo, ?User $autor): array
    {
        $errores = [];
        $nodos   = $grafo['nodos'] ?? null;
        $aristas = $grafo['aristas'] ?? [];

        if (! is_array($nodos) || $nodos === []) {
            return ['La automatización no tiene pasos.'];
        }

        if (count($nodos) > self::MAX_NODOS) {
            return ['Demasiados pasos (máximo ' . self::MAX_NODOS . ').'];
        }

        $ids = [];
        foreach ($nodos as $n) {
            $id = (string) ($n['id'] ?? '');

            if (! preg_match('/^[a-z0-9_]{1,40}$/', $id) || isset($ids[$id])) {
                return ['Hay un paso con un identificador inválido o repetido.'];
            }

            if (! in_array($n['tipo'] ?? null, self::TIPOS, true)) {
                return ['Hay un paso de un tipo que no existe.'];
            }

            $ids[$id] = $n;
        }

        $disparadores = array_filter($nodos, fn ($n) => $n['tipo'] === 'disparador');

        if (count($disparadores) !== 1) {
            return ['Una automatización necesita exactamente un disparador (qué la hace arrancar).'];
        }

        // ─── Aristas
        foreach ($aristas as $a) {
            $de = $a['de'] ?? null;
            $to = $a['a'] ?? null;

            if (! isset($ids[$de]) || ! isset($ids[$to])) {
                return ['Hay una conexión hacia un paso que no existe.'];
            }

            if (($ids[$to]['tipo'] ?? '') === 'disparador') {
                $errores[] = 'Nada puede conectarse hacia el disparador.';
            }

            $rama = $a['rama'] ?? null;

            if ($rama !== null && ! in_array($rama, ['si', 'no'], true)) {
                $errores[] = 'Una conexión tiene una rama inválida.';
            }

            if ($rama !== null && $ids[$de]['tipo'] !== 'condicion') {
                $errores[] = 'Solo una condición puede tener ramas «sí» y «no».';
            }
        }

        $salidas = [];
        foreach ($aristas as $a) {
            $clave = ($a['de'] ?? '') . '|' . ($a['rama'] ?? '');
            $salidas[$clave] = ($salidas[$clave] ?? 0) + 1;
        }
        if (collect($salidas)->contains(fn ($c) => $c > 1)) {
            $errores[] = 'Un paso no puede tener dos salidas iguales.';
        }

        if (self::tieneCiclo($ids, $aristas)) {
            $errores[] = 'La automatización da vueltas sobre sí misma. Un flujo debe avanzar siempre.';
        }

        $inicio = self::primero($grafo);
        $alcanzables = self::alcanzables($inicio, $aristas);
        if (count($alcanzables) !== count($ids)) {
            $errores[] = 'Hay pasos que nunca se alcanzan desde el disparador.';
        }

        // ─── Cada nodo
        $disparador = self::disparadorDe($grafo);
        $evento     = $disparador['tipo'] === 'evento' ? (CatalogoAutomatizaciones::eventos()[$disparador['clave']] ?? null) : null;

        foreach ($nodos as $n) {
            $errores = array_merge($errores, match ($n['tipo']) {
                'disparador' => self::validarDisparador($n['config'] ?? []),
                'condicion'  => self::validarCondicion($n['config'] ?? [], $evento),
                'espera'     => self::validarEspera($n['config'] ?? []),
                'accion'     => self::validarAccion($n, $evento, $autor),
            });
        }

        return array_values(array_unique($errores));
    }

    /** @return list<string> */
    private static function validarDisparador(array $c): array
    {
        $tipo = $c['tipo'] ?? null;

        if ($tipo === 'manual') {
            return [];
        }

        if ($tipo === 'evento') {
            $e = CatalogoAutomatizaciones::eventos()[$c['evento'] ?? ''] ?? null;

            if (! $e) {
                return ['Elige qué evento hace arrancar la automatización.'];
            }

            return Modulos::activo($e['modulo']) ? [] : ["El módulo de «{$e['label']}» está apagado: ese evento no ocurre."];
        }

        if ($tipo === 'horario') {
            $f = $c['frecuencia'] ?? [];

            if (! in_array($f['tipo'] ?? null, ['diaria', 'semanal'], true) || ! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) ($f['hora'] ?? ''))) {
                return ['El horario necesita frecuencia (diaria o semanal) y una hora válida.'];
            }

            if ($f['tipo'] === 'semanal' && empty($f['dias'])) {
                return ['Elige al menos un día de la semana.'];
            }

            return [];
        }

        return ['Elige cómo arranca la automatización: por un evento, a una hora o a mano.'];
    }

    /** @return list<string> */
    private static function validarCondicion(array $c, ?array $evento): array
    {
        if ($evento === null) {
            return ['Las condiciones solo se pueden usar cuando la automatización arranca por un evento: son sobre los datos del documento.'];
        }

        $reglas = $c['reglas'] ?? [];

        if (! is_array($reglas) || $reglas === []) {
            return ['Una condición necesita al menos una regla.'];
        }

        $errores = [];

        foreach ($reglas as $r) {
            if (! isset($evento['campos'][$r['campo'] ?? ''])) {
                $errores[] = 'Una condición mira un dato que ese evento no tiene.';
            }

            if (! isset(CatalogoAutomatizaciones::operadores()[$r['operador'] ?? ''])) {
                $errores[] = 'Una condición tiene un operador que no existe.';
            }
        }

        return $errores;
    }

    /** @return list<string> */
    private static function validarEspera(array $c): array
    {
        $unidades = CatalogoAutomatizaciones::unidadesDeEspera();
        $cantidad = (int) ($c['cantidad'] ?? 0);

        if ($cantidad < 1 || ! isset($unidades[$c['unidad'] ?? ''])) {
            return ['Una espera necesita una cantidad y una unidad (minutos, horas o días).'];
        }

        return $cantidad * $unidades[$c['unidad']] > self::MAX_ESPERA_MINUTOS
            ? ['Una espera no puede pasar de 90 días.']
            : [];
    }

    /** @return list<string> */
    private static function validarAccion(array $n, ?array $evento, ?User $autor): array
    {
        $a   = CatalogoAutomatizaciones::acciones()[$n['accion'] ?? ''] ?? null;
        $cfg = $n['config'] ?? [];

        if (! $a) {
            return ['Hay una acción que no existe.'];
        }

        $errores = [];

        foreach ($a['campos'] as $clave => $campo) {
            if (! empty($campo['requerido']) && blank($cfg[$clave] ?? null)) {
                $errores[] = "«{$a['label']}»: falta «{$campo['label']}».";
            }
        }

        if ($a['entidades'] !== null && ($evento === null || ! in_array($evento['entidad'], $a['entidades'], true))) {
            $errores[] = "«{$a['label']}» solo sirve cuando la automatización arranca por un evento de: " . implode(' o ', $a['entidades']) . '.';
        }

        if ($a['permiso'] && (! $autor || ! $autor->tienePermiso($a['permiso']))) {
            $errores[] = "«{$a['label']}» exige un permiso que no tienes ({$a['permiso']}). Una automatización no puede hacer lo que su autor no podría.";
        }

        if ($n['accion'] === 'notificar') {
            $dest = $cfg['destinatario'] ?? '';

            if (! in_array($dest, ['responsable', 'usuarios', 'permiso'], true)) {
                $errores[] = 'Avisar: elige a quién.';
            } elseif ($dest === 'responsable' && $evento === null) {
                $errores[] = 'Avisar al «responsable» solo sirve con un evento: sin documento no hay responsable.';
            } elseif ($dest === 'usuarios' && (empty($cfg['usuarios']) || ! is_array($cfg['usuarios']))) {
                $errores[] = 'Avisar: elige al menos una persona.';
            } elseif ($dest === 'permiso' && ! in_array($cfg['permiso'] ?? '', Permisos::todos(), true)) {
                $errores[] = 'Avisar: el permiso elegido no existe.';
            }
        }

        if ($n['accion'] === 'ejecutar_agente') {
            if (! Modulos::activo('equipo_ia')) {
                $errores[] = 'Poner a un agente a trabajar exige el módulo Equipo de IA encendido.';
            } elseif (! AgenteInterno::whereKey((int) ($cfg['agente_id'] ?? 0))->exists()) {
                $errores[] = 'El agente elegido ya no existe.';
            }
        }

        return $errores;
    }

    /** @param array<string, array> $ids */
    private static function tieneCiclo(array $ids, array $aristas): bool
    {
        $hijos = [];
        foreach ($aristas as $a) {
            $hijos[$a['de']][] = $a['a'];
        }

        $estado = [];
        $visitar = function (string $id) use (&$visitar, &$estado, $hijos): bool {
            $estado[$id] = 1;

            foreach ($hijos[$id] ?? [] as $h) {
                if (($estado[$h] ?? 0) === 1 || (($estado[$h] ?? 0) === 0 && $visitar($h))) {
                    return true;
                }
            }

            $estado[$id] = 2;

            return false;
        };

        foreach (array_keys($ids) as $id) {
            if (($estado[$id] ?? 0) === 0 && $visitar($id)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private static function alcanzables(?string $inicio, array $aristas): array
    {
        $vistos = [];
        $cola   = $inicio ? [$inicio] : [];

        while ($cola) {
            $id = array_pop($cola);

            if (isset($vistos[$id])) {
                continue;
            }

            $vistos[$id] = true;

            foreach ($aristas as $a) {
                if ($a['de'] === $id) {
                    $cola[] = $a['a'];
                }
            }
        }

        return array_keys($vistos);
    }
}
