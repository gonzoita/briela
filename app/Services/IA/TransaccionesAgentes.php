<?php

namespace App\Services\IA;

use App\Http\Controllers\AgenteIaController;
use App\Http\Controllers\EquipoIaController;
use App\Models\AgenteIa;
use App\Models\AgenteInterno;
use App\Models\CargoIa;
use App\Services\Agentes\LanzadorAgentes;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;

/**
 * Lo que la guía —el agente principal— puede hacer con los demás agentes: crear uno, cambiarle lo
 * que se le pida, encenderlo o apagarlo, ponerlo a trabajar, y ajustar a los que atienden clientes.
 *
 * Mismas reglas que todas las transacciones (ver `TransaccionesIaService`): **proponer no escribe**,
 * y al confirmar se guarda con **los mismos controladores que usa la pantalla** —con su validación,
 * su techo de herramientas por cargo y su revisión antes de encender—, no con un camino propio.
 * Un agente creado o cambiado desde el chat queda idéntico a uno hecho a mano, con su versión de
 * instrucciones registrada. Cada una exige el permiso de esa pantalla.
 */
trait TransaccionesAgentes
{
    public static function catalogoAgentes(): array
    {
        return [
            'configurar_agente' => [
                'descripcion' => 'Cambia un agente del Equipo de IA (los agentes internos que trabajan para la empresa): encenderlo o apagarlo, su horario, su tarea, sus instrucciones, si propone o solo lee, su nombre o su descripción. Cambia SOLO lo que se diga; lo demás queda igual.',
                'parametros'  => [
                    'agente'                => 'nombre del agente (obligatorio)',
                    'activo'                => 'si | no (opcional)',
                    'frecuencia'            => 'manual | diaria | semanal (opcional)',
                    'hora'                  => 'HH:MM, necesaria si es diaria o semanal (opcional)',
                    'dias'                  => 'si es semanal: días del 1 (lunes) al 7 (domingo), separados por coma (opcional)',
                    'tarea'                 => 'la tarea completa nueva (opcional)',
                    'instrucciones'         => 'instrucciones completas nuevas: REEMPLAZAN las actuales (opcional)',
                    'agregar_instrucciones' => 'texto que se SUMA al final de las instrucciones actuales (opcional)',
                    'modo'                  => 'propone | solo_lectura (opcional)',
                    'nombre_nuevo'          => 'nuevo nombre (opcional)',
                    'descripcion'           => 'nueva descripción corta (opcional)',
                ],
                'permiso'     => 'equipo_ia.gestionar',
            ],
            'crear_agente' => [
                'descripcion' => 'Crea un agente nuevo en el Equipo de IA. Nace APAGADO: se enciende después, con «configurar_agente», cuando la persona lo pida.',
                'parametros'  => [
                    'nombre'        => 'nombre del agente (obligatorio)',
                    'cargo'         => 'nombre del cargo que tendrá; decide qué datos puede leer (obligatorio si hay varios cargos)',
                    'tarea'         => 'qué tiene que hacer cada vez que corre (obligatorio)',
                    'instrucciones' => 'cómo debe trabajar (opcional)',
                    'descripcion'   => 'descripción corta (opcional)',
                    'modo'          => 'propone | solo_lectura (opcional; por defecto solo_lectura)',
                    'frecuencia'    => 'manual | diaria | semanal (opcional; por defecto manual)',
                    'hora'          => 'HH:MM si es diaria o semanal (opcional)',
                ],
                'permiso'     => 'equipo_ia.gestionar',
            ],
            'ejecutar_agente' => [
                'descripcion' => 'Pone a trabajar ya a un agente del Equipo de IA que esté encendido.',
                'parametros'  => ['agente' => 'nombre del agente (obligatorio)'],
                'permiso'     => 'equipo_ia.ejecutar',
            ],
            'configurar_agente_atencion' => [
                'descripcion' => 'Cambia un agente de ATENCIÓN A CLIENTES (los que contestan por WhatsApp, redes o la web): encenderlo o apagarlo, sus instrucciones, su saludo o su horario. Cambia SOLO lo que se diga.',
                'parametros'  => [
                    'agente'                => 'nombre del agente de atención (obligatorio)',
                    'activo'                => 'si | no (opcional)',
                    'instrucciones'         => 'instrucciones completas nuevas: REEMPLAZAN las actuales (opcional)',
                    'agregar_instrucciones' => 'texto que se SUMA al final de las instrucciones (opcional)',
                    'saludo'                => 'nuevo saludo (opcional)',
                    'desde'                 => 'hora de inicio de atención HH:MM (opcional)',
                    'hasta'                 => 'hora de fin de atención HH:MM (opcional)',
                ],
                'permiso'     => 'agentes.gestionar',
            ],
        ];
    }

    // ─── Configurar un agente interno ────────────────────────────────────────

    private function prepararConfigurarAgente(array $p): array
    {
        $agente = $this->agenteInterno($p['agente'] ?? '');
        $actual = $this->datosAgente($agente);
        $cambios = [];
        $lineas = [];

        if (($v = $this->siNo($p['activo'] ?? null)) !== null && $v !== (bool) $agente->activo) {
            $cambios['activo'] = $v;
            $lineas[] = $v ? 'encenderlo' : 'apagarlo';
        }

        if (filled($p['frecuencia'] ?? null) || filled($p['hora'] ?? null) || filled($p['dias'] ?? null)) {
            $tipo = filled($p['frecuencia'] ?? null) ? strtolower(trim($p['frecuencia'])) : ($actual['frecuencia']['tipo'] ?? 'manual');
            if (! in_array($tipo, ['manual', 'diaria', 'semanal'], true)) {
                throw new FaltaDato('¿Lo quieres manual, diario o semanal?');
            }
            $hora = filled($p['hora'] ?? null) ? $this->hora($p['hora']) : ($actual['frecuencia']['hora'] ?? '');
            if ($tipo !== 'manual' && ! $hora) {
                throw new FaltaDato('¿A qué hora debe correr?');
            }
            $dias = filled($p['dias'] ?? null)
                ? array_values(array_unique(array_filter(array_map('intval', preg_split('/[\s,;]+/', (string) $p['dias'])), fn ($d) => $d >= 1 && $d <= 7)))
                : ($actual['frecuencia']['dias'] ?? []);

            $cambios['frecuencia'] = ['tipo' => $tipo, 'hora' => $tipo === 'manual' ? '' : $hora, 'dias' => $tipo === 'semanal' ? $dias : []];
            $lineas[] = 'horario: ' . $this->describirFrecuencia($cambios['frecuencia']);
        }

        if (filled($p['tarea'] ?? null)) {
            $cambios['tarea'] = trim($p['tarea']);
            $lineas[] = 'tarea nueva: «' . \Illuminate\Support\Str::limit($cambios['tarea'], 160) . '»';
        }

        if (filled($p['instrucciones'] ?? null)) {
            $cambios['instrucciones'] = trim($p['instrucciones']);
            $lineas[] = 'reemplazar las instrucciones por: «' . \Illuminate\Support\Str::limit($cambios['instrucciones'], 200) . '»';
        } elseif (filled($p['agregar_instrucciones'] ?? null)) {
            $cambios['instrucciones'] = rtrim((string) $agente->instrucciones) . "\n\n" . trim($p['agregar_instrucciones']);
            $lineas[] = 'sumar a las instrucciones: «' . \Illuminate\Support\Str::limit(trim($p['agregar_instrucciones']), 200) . '»';
        }

        if (filled($p['modo'] ?? null)) {
            $modo = str_contains(strtolower($p['modo']), 'prop') ? 'propone' : 'solo_lectura';
            if ($modo !== $agente->modo) {
                $cambios['modo'] = $modo;
                $lineas[] = $modo === 'propone' ? 'que deje propuestas para aprobar' : 'que solo lea, sin proponer';
            }
        }

        if (filled($p['nombre_nuevo'] ?? null)) {
            $cambios['nombre'] = trim($p['nombre_nuevo']);
            $lineas[] = "llamarlo «{$cambios['nombre']}»";
        }

        if (filled($p['descripcion'] ?? null)) {
            $cambios['descripcion'] = trim($p['descripcion']);
            $lineas[] = 'descripción nueva';
        }

        if ($cambios === []) {
            throw new FaltaDato("¿Qué quieres cambiarle a «{$agente->nombre}»? Puedo encenderlo o apagarlo, cambiar su horario, su tarea, sus instrucciones o si propone.");
        }

        return [
            ['agente_id' => $agente->id, 'cambios' => $cambios],
            "Cambiar al agente «{$agente->nombre}»: " . implode('; ', $lineas) . '.',
        ];
    }

    private function hacerConfigurarAgente(array $p): array
    {
        $agente = AgenteInterno::findOrFail($p['agente_id']);
        $datos = array_replace($this->datosAgente($agente), $p['cambios']);

        $r = $this->llamarPantalla(fn () => app(EquipoIaController::class)
            ->actualizarAgente(Request::create('/', 'PUT', $datos), $agente));

        return $r ?? ['exito' => true, 'mensaje' => "Listo: «{$agente->fresh()->nombre}» quedó actualizado.", 'url' => '/configuracion/agentes?equipo=1'];
    }

    // ─── Crear un agente interno ─────────────────────────────────────────────

    private function prepararCrearAgente(array $p): array
    {
        $nombre = trim((string) ($p['nombre'] ?? ''));
        if ($nombre === '') {
            throw new FaltaDato('¿Cómo se va a llamar el agente?');
        }

        $tarea = trim((string) ($p['tarea'] ?? ''));
        if ($tarea === '') {
            throw new FaltaDato("¿Qué tiene que hacer «{$nombre}» cada vez que trabaje?");
        }

        $cargos = CargoIa::orderBy('nombre')->get(['id', 'nombre', 'herramientas']);
        if ($cargos->isEmpty()) {
            throw new FaltaDato('Todavía no hay cargos en el Equipo de IA. Crea uno en la pestaña «Cargos»: el cargo decide qué datos puede leer el agente.');
        }

        $texto = trim((string) ($p['cargo'] ?? ''));
        $cargo = $texto === ''
            ? ($cargos->count() === 1 ? $cargos->first() : null)
            : ($cargos->first(fn ($c) => mb_strtolower($c->nombre) === mb_strtolower($texto))
                ?? $cargos->first(fn ($c) => str_contains(mb_strtolower($c->nombre), mb_strtolower($texto))));

        if (! $cargo) {
            throw new FaltaDato('¿Con qué cargo? El cargo decide qué datos puede leer. Hay: ' . $cargos->pluck('nombre')->implode(', ') . '.');
        }

        $frecuencia = ['tipo' => 'manual', 'hora' => '', 'dias' => []];
        if (filled($p['frecuencia'] ?? null) && strtolower($p['frecuencia']) !== 'manual') {
            $hora = $this->hora($p['hora'] ?? '');
            if (! $hora) {
                throw new FaltaDato('¿A qué hora debe correr?');
            }
            $frecuencia = ['tipo' => strtolower($p['frecuencia']) === 'semanal' ? 'semanal' : 'diaria', 'hora' => $hora, 'dias' => []];
        }

        $datos = [
            'nombre'        => $nombre,
            'descripcion'   => $p['descripcion'] ?? null,
            'cargo_id'      => $cargo->id,
            'tarea'         => $tarea,
            'instrucciones' => $p['instrucciones'] ?? null,
            'herramientas'  => $cargo->herramientas ?? [],
            'frecuencia'    => $frecuencia,
            'modo'          => str_contains(strtolower((string) ($p['modo'] ?? '')), 'prop') ? 'propone' : 'solo_lectura',
        ];

        return [
            $datos,
            "Crear el agente «{$nombre}» con el cargo «{$cargo->nombre}», " . $this->describirFrecuencia($frecuencia)
                . ', ' . ($datos['modo'] === 'propone' ? 'que deja propuestas para aprobar' : 'que solo lee')
                . '. Tarea: «' . \Illuminate\Support\Str::limit($tarea, 160) . '». Nace apagado.',
        ];
    }

    private function hacerCrearAgente(array $p): array
    {
        $r = $this->llamarPantalla(fn () => app(EquipoIaController::class)->crearAgente(Request::create('/', 'POST', $p)));

        return $r ?? ['exito' => true, 'mensaje' => "Listo: creé a «{$p['nombre']}», apagado. Cuando quieras lo enciendo.", 'url' => '/configuracion/agentes?equipo=1'];
    }

    // ─── Ponerlo a trabajar ──────────────────────────────────────────────────

    private function prepararEjecutarAgente(array $p): array
    {
        $agente = $this->agenteInterno($p['agente'] ?? '');

        if (! $agente->activo) {
            throw new FaltaDato("«{$agente->nombre}» está apagado. ¿Lo enciendo primero?");
        }

        if (LanzadorAgentes::pausado()) {
            throw new FaltaDato('El Equipo de IA está en pausa general. Hay que reanudarlo antes de poner a trabajar a alguien.');
        }

        return [['agente_id' => $agente->id], "Poner a trabajar ya a «{$agente->nombre}»."];
    }

    private function hacerEjecutarAgente(array $p): array
    {
        $agente = AgenteInterno::findOrFail($p['agente_id']);

        $r = $this->llamarPantalla(fn () => app(EquipoIaController::class)
            ->ejecutarAgente($agente, app(LanzadorAgentes::class)));

        return $r ?? ['exito' => true, 'mensaje' => "Listo: «{$agente->nombre}» ya está trabajando. Lo ves en la oficina en vivo.", 'url' => '/configuracion/agentes?equipo=1'];
    }

    // ─── Un agente de atención a clientes ────────────────────────────────────

    private function prepararConfigurarAgenteAtencion(array $p): array
    {
        $texto = trim((string) ($p['agente'] ?? ''));
        $todos = AgenteIa::orderBy('orden')->orderBy('id')->get();

        if ($todos->isEmpty()) {
            throw new FaltaDato('No hay agentes de atención a clientes todavía. Se crean en Agentes → Atención a clientes.');
        }

        $agente = $texto === '' && $todos->count() === 1 ? $todos->first() : $this->porNombre($todos, $texto);

        if (! $agente) {
            throw new FaltaDato('¿Cuál agente de atención? Hay: ' . $todos->pluck('nombre')->implode(', ') . '.');
        }

        $cambios = [];
        $lineas = [];

        if (($v = $this->siNo($p['activo'] ?? null)) !== null && $v !== (bool) $agente->activo) {
            $cambios['activo'] = $v;
            $lineas[] = $v ? 'encenderlo (empieza a contestar)' : 'apagarlo (deja de contestar)';
        }

        if (filled($p['instrucciones'] ?? null)) {
            $cambios['instrucciones'] = trim($p['instrucciones']);
            $lineas[] = 'reemplazar sus instrucciones';
        } elseif (filled($p['agregar_instrucciones'] ?? null)) {
            $cambios['instrucciones'] = rtrim((string) $agente->instrucciones) . "\n\n" . trim($p['agregar_instrucciones']);
            $lineas[] = 'sumar a sus instrucciones: «' . \Illuminate\Support\Str::limit(trim($p['agregar_instrucciones']), 200) . '»';
        }

        if (filled($p['saludo'] ?? null)) {
            $cambios['saludo'] = trim($p['saludo']);
            $lineas[] = 'saludo nuevo: «' . \Illuminate\Support\Str::limit($cambios['saludo'], 120) . '»';
        }

        if (filled($p['desde'] ?? null) || filled($p['hasta'] ?? null)) {
            $desde = filled($p['desde'] ?? null) ? $this->hora($p['desde']) : ($agente->horario['desde'] ?? '');
            $hasta = filled($p['hasta'] ?? null) ? $this->hora($p['hasta']) : ($agente->horario['hasta'] ?? '');
            if (! $desde || ! $hasta) {
                throw new FaltaDato('¿Desde qué hora y hasta qué hora atiende?');
            }
            $cambios['horario'] = ['desde' => $desde, 'hasta' => $hasta];
            $lineas[] = "horario de {$desde} a {$hasta}";
        }

        if ($cambios === []) {
            throw new FaltaDato("¿Qué quieres cambiarle a «{$agente->nombre}»? Puedo encenderlo o apagarlo, cambiar sus instrucciones, su saludo o su horario.");
        }

        return [['agente_id' => $agente->id, 'cambios' => $cambios], "Cambiar al agente de atención «{$agente->nombre}»: " . implode('; ', $lineas) . '.'];
    }

    private function hacerConfigurarAgenteAtencion(array $p): array
    {
        $agente = AgenteIa::findOrFail($p['agente_id']);
        $datos = array_replace($agente->only([
            'nombre', 'descripcion', 'activo', 'perfil', 'canales', 'herramientas',
            'instrucciones', 'saludo', 'escalamiento', 'horario',
        ]), $p['cambios']);

        $r = $this->llamarPantalla(fn () => app(AgenteIaController::class)->update(Request::create('/', 'PUT', $datos), $agente));

        return $r ?? ['exito' => true, 'mensaje' => "Listo: «{$agente->nombre}» quedó actualizado.", 'url' => '/configuracion/agentes'];
    }

    // ─── Piezas ──────────────────────────────────────────────────────────────

    /** El agente interno por su nombre. Si no se encuentra, la guía pregunta con la lista a la vista. */
    private function agenteInterno(string $texto): AgenteInterno
    {
        $todos = AgenteInterno::orderBy('orden')->orderBy('id')->get();

        if ($todos->isEmpty()) {
            throw new FaltaDato('El Equipo de IA todavía no tiene agentes. ¿Creo uno?');
        }

        $agente = $this->porNombre($todos, trim($texto));

        if (! $agente) {
            throw new FaltaDato('¿Cuál agente? Hay: ' . $todos->pluck('nombre')->implode(', ') . '.');
        }

        return $agente;
    }

    private function porNombre($lista, string $texto)
    {
        if ($texto === '') {
            return null;
        }

        $t = mb_strtolower($texto);
        $exacto = $lista->first(fn ($a) => mb_strtolower($a->nombre) === $t);
        if ($exacto) {
            return $exacto;
        }

        $parecidos = $lista->filter(fn ($a) => str_contains(mb_strtolower($a->nombre), $t) || str_contains($t, mb_strtolower($a->nombre)));

        return $parecidos->count() === 1 ? $parecidos->first() : null;
    }

    /** Lo que la pantalla de edición manda hoy, para cambiar solo lo pedido y dejar igual lo demás. */
    private function datosAgente(AgenteInterno $a): array
    {
        return [
            'nombre'               => $a->nombre,
            'descripcion'          => $a->descripcion,
            'avatar'               => $a->avatar,
            'color'                => $a->color,
            'cargo_id'             => $a->cargo_id,
            'instrucciones'        => $a->instrucciones,
            'tarea'                => $a->tarea,
            'herramientas'         => $a->herramientas ?? [],
            'modelo'               => $a->modelo,
            'modelo_respaldo'      => $a->modelo_respaldo,
            'nivel_modelo'         => $a->nivel_modelo,
            'temperatura'          => $a->temperatura,
            'max_tokens'           => $a->max_tokens,
            'max_pasos'            => $a->max_pasos,
            'max_tokens_ejecucion' => $a->max_tokens_ejecucion,
            'frecuencia'           => $a->frecuencia ?? ['tipo' => 'manual', 'hora' => '', 'dias' => []],
            'modo'                 => $a->modo,
            'avisar_a'             => $a->avisar_a ?? [],
            'sede_id'              => $a->sede_id,
            'activo'               => (bool) $a->activo,
        ];
    }

    /**
     * Llama al controlador de la pantalla y traduce su respuesta. Devuelve null si salió bien, o
     * el error con el mismo mensaje que vería quien lo hace a mano.
     */
    private function llamarPantalla(callable $llamada): ?array
    {
        try {
            $respuesta = $llamada();
        } catch (HttpResponseException $e) {
            $respuesta = $e->getResponse();
        }

        $estado = method_exists($respuesta, 'getStatusCode') ? $respuesta->getStatusCode() : 200;

        if ($estado >= 400) {
            $datos = json_decode((string) $respuesta->getContent(), true) ?? [];

            return ['exito' => false, 'mensaje' => $datos['message'] ?? 'No se pudo.'];
        }

        return null;
    }

    private function siNo(mixed $v): ?bool
    {
        if (is_bool($v)) {
            return $v;
        }
        $t = mb_strtolower(trim((string) $v));
        if ($t === '') {
            return null;
        }

        return in_array($t, ['si', 'sí', 'true', '1', 'encender', 'encendido', 'activo', 'activar', 'on'], true);
    }

    private function hora(mixed $v): ?string
    {
        if (! preg_match('/^\s*(\d{1,2})(?::(\d{2}))?\s*(am|pm|a\.m\.|p\.m\.)?\s*$/i', (string) $v, $m)) {
            return null;
        }
        $h = (int) $m[1];
        $min = (int) ($m[2] ?? 0);
        $sufijo = strtolower(str_replace('.', '', $m[3] ?? ''));
        if ($sufijo === 'pm' && $h < 12) $h += 12;
        if ($sufijo === 'am' && $h === 12) $h = 0;

        return $h <= 23 && $min <= 59 ? sprintf('%02d:%02d', $h, $min) : null;
    }

    private function describirFrecuencia(array $f): string
    {
        return match ($f['tipo'] ?? 'manual') {
            'diaria'  => "todos los días a las {$f['hora']}",
            'semanal' => "semanal a las {$f['hora']}",
            default   => 'solo cuando se le pida',
        };
    }
}
