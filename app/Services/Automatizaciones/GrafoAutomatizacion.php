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

    public const TIPOS = ['disparador', 'condicion', 'accion', 'espera', 'aprobacion'];

    /** Lo más que una espera puede durar: más que eso es un olvido, no una espera. */
    public const MAX_ESPERA_MINUTOS = 90 * 1440;

    /** Lo más que una aprobación puede quedar abierta. */
    public const MAX_APROBACION_HORAS = 168;

    public const FRECUENCIAS = ['diaria', 'semanal', 'mensual', 'cada_horas'];

    public const INTERVALOS = [1, 2, 3, 4, 6, 12];

    // ─── Recorrer ────────────────────────────────────────────────────────────

    public static function nodo(array $grafo, ?string $id): ?array
    {
        return collect($grafo['nodos'] ?? [])->firstWhere('id', $id);
    }

    public static function primero(array $grafo): ?string
    {
        return collect($grafo['nodos'] ?? [])->firstWhere('tipo', 'disparador')['id'] ?? null;
    }

    /**
     * El nodo que sigue a otro. Una condición o una aprobación eligen su rama; si la rama «sí» no
     * está dibujada pero hay una salida sin rama, esa es la que sigue (así se arma una cadena).
     */
    public static function siguiente(array $grafo, string $desde, ?string $rama = null): ?string
    {
        foreach ($grafo['aristas'] ?? [] as $a) {
            if (($a['de'] ?? null) === $desde && ($a['rama'] ?? null) === $rama) {
                return $a['a'] ?? null;
            }
        }

        if ($rama === 'si') {
            return self::siguiente($grafo, $desde, null);
        }

        return null;
    }

    /** @return array{tipo: string, clave: ?string, entidad: ?string} */
    public static function disparadorDe(array $grafo): array
    {
        $cfg  = self::nodo($grafo, self::primero($grafo))['config'] ?? [];
        $tipo = (string) ($cfg['tipo'] ?? 'manual');

        if ($tipo === 'evento') {
            $clave = $cfg['evento'] ?? null;

            return ['tipo' => 'evento', 'clave' => $clave, 'entidad' => CatalogoAutomatizaciones::eventos()[$clave ?? '']['entidad'] ?? null];
        }

        if ($tipo === 'horario') {
            $entidad = filled($cfg['para_cada'] ?? null) ? (string) $cfg['para_cada'] : null;

            return ['tipo' => 'horario', 'clave' => $entidad, 'entidad' => $entidad];
        }

        return ['tipo' => 'manual', 'clave' => null, 'entidad' => null];
    }

    /** Sobre qué documento trabaja la automatización, o null si no trabaja sobre ninguno. */
    public static function entidadDe(array $grafo): ?string
    {
        return self::disparadorDe($grafo)['entidad'];
    }

    // ─── Validar ─────────────────────────────────────────────────────────────

    /**
     * Todo lo que está mal en un grafo, en frases para una persona. Vacío = se puede guardar.
     *
     * Revisa también que **quien la arma pueda hacer lo que pide**: una automatización actúa con
     * los permisos de su autor, y poder armarlas no debe ser una puerta lateral a acciones que su
     * pantalla no le deja hacer, ni a documentos que no puede ver.
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

        if (! is_array($aristas)) {
            return ['Las conexiones de la automatización están dañadas.'];
        }

        if (count($nodos) > self::MAX_NODOS) {
            return ['Demasiados pasos (máximo ' . self::MAX_NODOS . ').'];
        }

        $ids = [];
        foreach ($nodos as $n) {
            if (! is_array($n)) {
                return ['Hay un paso dañado.'];
            }

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

            if (! is_string($de) || ! is_string($to) || ! isset($ids[$de]) || ! isset($ids[$to])) {
                return ['Hay una conexión hacia un paso que no existe.'];
            }

            if (($ids[$to]['tipo'] ?? '') === 'disparador') {
                $errores[] = 'Nada puede conectarse hacia el disparador.';
            }

            $rama = $a['rama'] ?? null;

            if ($rama !== null && ! in_array($rama, ['si', 'no'], true)) {
                $errores[] = 'Una conexión tiene una rama inválida.';
            }

            if ($rama !== null && ! in_array($ids[$de]['tipo'], ['condicion', 'aprobacion'], true)) {
                $errores[] = 'Solo una condición o una aprobación pueden tener ramas «sí» y «no».';
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

        foreach ($aristas as $a) {
            if (($a['rama'] ?? null) === 'si' && isset($salidas[($a['de'] ?? '') . '|'])) {
                $errores[] = 'Un paso no puede tener a la vez una salida «sí» y una sin rama.';
            }
        }

        if (self::tieneCiclo($ids, $aristas)) {
            $errores[] = 'La automatización da vueltas sobre sí misma. Un flujo debe avanzar siempre.';
        }

        $inicio = self::primero($grafo);
        $alcanzables = self::alcanzables($inicio, $aristas);
        if (count($alcanzables) !== count($ids)) {
            $errores[] = 'Hay pasos que nunca se alcanzan desde el disparador.';
        }

        if (! collect($nodos)->contains(fn ($n) => $n['tipo'] === 'accion')) {
            $errores[] = 'La automatización no hace nada: agrega al menos una acción.';
        }

        // ─── Cada nodo
        $disparador = self::disparadorDe($grafo);
        $entidad    = $disparador['entidad'];

        foreach ($nodos as $n) {
            $errores = array_merge($errores, match ($n['tipo']) {
                'disparador' => self::validarDisparador($n['config'] ?? [], $autor),
                'condicion'  => self::validarCondicion($n['config'] ?? [], $entidad),
                'espera'     => self::validarEspera($n['config'] ?? []),
                'aprobacion' => self::validarAprobacion($n['config'] ?? [], $entidad),
                'accion'     => self::validarAccion($n, $entidad, $autor),
            });
        }

        return array_values(array_unique($errores));
    }

    /** @return list<string> */
    private static function validarDisparador(array $c, ?User $autor): array
    {
        $tipo    = $c['tipo'] ?? null;
        $errores = [];

        $noRepetir = $c['no_repetir_dias'] ?? null;
        if ($noRepetir !== null && $noRepetir !== '' && (! is_numeric($noRepetir) || (int) $noRepetir < 0 || (int) $noRepetir > 365)) {
            $errores[] = '«No repetir con el mismo documento» acepta de 0 a 365 días.';
        }

        if ($tipo === 'manual') {
            return $errores;
        }

        if ($tipo === 'evento') {
            $e = CatalogoAutomatizaciones::eventos()[$c['evento'] ?? ''] ?? null;

            if (! $e) {
                return ['Elige qué evento hace arrancar la automatización.'];
            }

            if (! EntidadesAutomatizacion::disponible($e['entidad'])) {
                $errores[] = "El módulo de «{$e['label']}» está apagado: ese evento no ocurre.";
            }

            return array_merge($errores, self::puedeVer($e['entidad'], $autor));
        }

        if ($tipo === 'horario') {
            $f    = $c['frecuencia'] ?? [];
            $freq = $f['tipo'] ?? null;
            $hora = fn ($h) => is_string($h) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $h);

            if (! in_array($freq, self::FRECUENCIAS, true)) {
                return ['Elige cada cuánto corre: todos los días, ciertos días, una vez al mes o cada pocas horas.'];
            }

            if ($freq === 'cada_horas') {
                if (! in_array((int) ($f['intervalo'] ?? 0), self::INTERVALOS, true)) {
                    $errores[] = 'Elige cada cuántas horas (1, 2, 3, 4, 6 o 12).';
                }

                if (! $hora($f['desde'] ?? null) || ! $hora($f['hasta'] ?? null) || ($f['desde'] ?? '') >= ($f['hasta'] ?? '')) {
                    $errores[] = 'Elige entre qué horas corre (desde antes que hasta).';
                }
            } elseif (! $hora($f['hora'] ?? null)) {
                $errores[] = 'Elige una hora válida.';
            }

            if ($freq === 'semanal' && empty($f['dias'])) {
                $errores[] = 'Elige al menos un día de la semana.';
            }

            if ($freq === 'mensual' && ((int) ($f['dia_mes'] ?? 0) < 1 || (int) ($f['dia_mes'] ?? 0) > 28)) {
                $errores[] = 'Elige un día del mes entre 1 y 28 (los que existen en todos los meses).';
            }

            if (filled($c['para_cada'] ?? null)) {
                $ent = (string) $c['para_cada'];
                $def = EntidadesAutomatizacion::def($ent);

                if (! $def || $def['barrido'] === null) {
                    $errores[] = 'Ese tipo de documento no se puede revisar por horario.';
                } else {
                    if (! EntidadesAutomatizacion::disponible($ent)) {
                        $errores[] = "El módulo de «{$def['plural']}» está apagado.";
                    }

                    $errores = array_merge($errores, self::puedeVer($ent, $autor));
                }
            }

            return $errores;
        }

        return ['Elige cómo arranca la automatización: por un evento, a una hora o a mano.'];
    }

    /** @return list<string> */
    private static function puedeVer(string $entidad, ?User $autor): array
    {
        $def = EntidadesAutomatizacion::def($entidad);

        if (! $def || ! $autor || $autor->tienePermiso($def['permiso'])) {
            return [];
        }

        return ["No puedes ver «{$def['plural']}» ({$def['permiso']}), así que tampoco puedes automatizar sobre ellas."];
    }

    /** @return list<string> */
    private static function validarCondicion(array $c, ?string $entidad): array
    {
        $reglas = $c['reglas'] ?? [];

        if (! is_array($reglas) || $reglas === []) {
            return ['Una condición necesita al menos una regla.'];
        }

        $campos  = CatalogoAutomatizaciones::camposDe($entidad);
        $errores = [];

        foreach ($reglas as $r) {
            if (! isset($campos[$r['campo'] ?? ''])) {
                $errores[] = $entidad === null
                    ? 'Sin un documento, las condiciones solo pueden mirar la hora, el día o lo que dejó la IA.'
                    : 'Una condición mira un dato que ese documento no tiene.';
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
    private static function validarAprobacion(array $c, ?string $entidad): array
    {
        $errores = self::validarDestinatario($c, $entidad, 'Pedir aprobación');

        if (blank($c['pregunta'] ?? null)) {
            $errores[] = 'Pedir aprobación: escribe qué se le pregunta a la persona.';
        }

        $horas = (int) ($c['vence_horas'] ?? 48);
        if ($horas < 1 || $horas > self::MAX_APROBACION_HORAS) {
            $errores[] = 'Una aprobación puede esperar de 1 a 168 horas (7 días).';
        }

        return $errores;
    }

    /** @return list<string> */
    private static function validarDestinatario(array $cfg, ?string $entidad, string $nombre): array
    {
        $dest = $cfg['destinatario'] ?? '';

        return match (true) {
            ! in_array($dest, ['responsable', 'usuarios', 'permiso', 'autor'], true) => ["{$nombre}: elige a quién."],
            $dest === 'responsable' && $entidad === null => ["{$nombre}: «el responsable» solo existe cuando hay un documento."],
            $dest === 'usuarios' && (empty($cfg['usuarios']) || ! is_array($cfg['usuarios'])) => ["{$nombre}: elige al menos una persona."],
            $dest === 'usuarios' && User::whereIn('id', array_map('intval', $cfg['usuarios']))->count() !== count(array_unique($cfg['usuarios'])) => ["{$nombre}: una de las personas elegidas ya no existe."],
            $dest === 'permiso' && ! in_array($cfg['permiso'] ?? '', Permisos::todos(), true) => ["{$nombre}: el permiso elegido no existe."],
            default => [],
        };
    }

    /** @return list<string> */
    private static function validarAccion(array $n, ?string $entidad, ?User $autor): array
    {
        $a   = CatalogoAutomatizaciones::acciones()[$n['accion'] ?? ''] ?? null;
        $cfg = $n['config'] ?? [];

        if (! $a) {
            return ['Hay una acción que no existe.'];
        }

        if (! is_array($cfg)) {
            return ["«{$a['label']}»: la configuración está dañada."];
        }

        $errores = [];

        foreach ($a['campos'] as $clave => $campo) {
            $aplica = ! isset($campo['si']) || collect($campo['si'])->every(fn ($v, $k) => ($cfg[$k] ?? null) === $v);

            if ($aplica && ! empty($campo['requerido']) && blank($cfg[$clave] ?? null)) {
                $errores[] = "«{$a['label']}»: falta «{$campo['label']}».";
            }

            if (($campo['tipo'] ?? '') === 'opciones' && filled($cfg[$clave] ?? null) && ! in_array($cfg[$clave], $campo['opciones'], true)) {
                $errores[] = "«{$a['label']}»: «{$campo['label']}» tiene un valor que no existe.";
            }
        }

        if ($a['modulo'] !== null && ! Modulos::activo($a['modulo'])) {
            $errores[] = "«{$a['label']}» necesita un módulo que está apagado.";
        }

        if ($a['entidades'] !== null && ($entidad === null || ! in_array($entidad, $a['entidades'], true))) {
            $nombres = collect($a['entidades'])->map(fn ($e) => mb_strtolower(EntidadesAutomatizacion::def($e)['label'] ?? $e))->implode(', ');
            $errores[] = "«{$a['label']}» solo sirve cuando la automatización trabaja sobre: {$nombres}.";
        }

        if ($a['sin_sensibles'] && $entidad !== null && (EntidadesAutomatizacion::def($entidad)['sensible'] ?? false)) {
            $errores[] = "«{$a['label']}» no se puede usar con datos de colaboradores: nunca salen del sistema ni van a la IA.";
        }

        if ($a['permiso'] && (! $autor || ! $autor->tienePermiso($a['permiso']))) {
            $errores[] = "«{$a['label']}» exige un permiso que no tienes ({$a['permiso']}). Una automatización no puede hacer lo que su autor no podría.";
        }

        $errores = array_merge($errores, match ($n['accion']) {
            'notificar'              => self::validarDestinatario($cfg, $entidad, 'Avisar'),
            'mover_lead'             => self::validarEtapa($cfg),
            'asignar_lead', 'asignar_conversacion' => self::validarUsuarios($cfg),
            'clasificar_ia'          => count(array_filter(array_map('trim', explode(',', (string) ($cfg['categorias'] ?? ''))))) < 2
                ? ['Clasificar con IA: escribe al menos dos categorías, separadas por coma.'] : [],
            'webhook'                => Webhook::errorDeDireccion((string) ($cfg['url'] ?? ''), resolver: false) !== null
                ? ['Webhook: ' . Webhook::errorDeDireccion((string) ($cfg['url'] ?? ''), resolver: false)] : [],
            'ejecutar_agente'        => self::validarAgente($cfg),
            'crear_solicitud_compra' => isset($cfg['multiplicador']) && ((int) $cfg['multiplicador'] < 1 || (int) $cfg['multiplicador'] > 10)
                ? ['Solicitud de compra: el multiplicador va de 1 a 10.'] : [],
            'comentar_documento'     => isset($cfg['dias']) && ((int) $cfg['dias'] < 0 || (int) $cfg['dias'] > 90)
                ? ['Comentario: el plazo va de 0 a 90 días.'] : [],
            'crear_tarea_crm'        => isset($cfg['dias']) && ((int) $cfg['dias'] < 0 || (int) $cfg['dias'] > 90)
                ? ['Tarea del CRM: el plazo va de 0 a 90 días.'] : [],
            default                  => [],
        });

        return $errores;
    }

    /** @return list<string> */
    private static function validarEtapa(array $cfg): array
    {
        $etapa = \App\Models\CrmEtapa::find((int) ($cfg['etapa_id'] ?? 0));

        return match (true) {
            ! $etapa                               => ['Mover el lead: la etapa elegida ya no existe.'],
            $etapa->es_ganado || $etapa->es_perdido => ['Mover el lead: una automatización no gana ni pierde leads; elige una etapa abierta.'],
            default                                => [],
        };
    }

    /** @return list<string> */
    private static function validarUsuarios(array $cfg): array
    {
        $ids = array_map('intval', (array) ($cfg['usuarios'] ?? []));

        return $ids === [] || User::whereIn('id', $ids)->where('activo', true)->count() !== count(array_unique($ids))
            ? ['Asignar: elige personas activas.'] : [];
    }

    /** @return list<string> */
    private static function validarAgente(array $cfg): array
    {
        return AgenteInterno::whereKey((int) ($cfg['agente_id'] ?? 0))->exists() ? [] : ['El agente elegido ya no existe.'];
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
