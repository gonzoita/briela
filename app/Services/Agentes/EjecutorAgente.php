<?php

namespace App\Services\Agentes;

use App\Exceptions\IaException;
use App\Models\AgenteEjecucion;
use App\Models\AgenteInterno;
use App\Models\AgentePaso;
use App\Models\AgentePropuesta;
use App\Models\User;
use App\Services\IA\IaService;
use App\Services\NotificacionService;
use App\Support\Modulos;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Corre un agente de principio a fin: el bucle entre el modelo y sus herramientas.
 *
 * El modelo **no** toca datos: pide una herramienta por nombre, el servidor la ejecuta con el
 * rol del usuario de servicio del agente y le devuelve lo que sale. Cuando ya no pide más,
 * entrega un resumen. Todo lo que quiera cambiar lo deja como propuesta.
 *
 * Tres frenos que no dependen del dinero —el dinero lo corta el cupo del superadmin—:
 * máximo de pasos, máximo de tokens por corrida y un cierre forzado si se acaban.
 */
class EjecutorAgente
{
    private int $orden = 0;

    public function __construct(
        private readonly IaService $ia,
        private readonly CatalogoHerramientas $herramientas,
        private readonly ArmadorPrompt $prompt,
    ) {}

    public function ejecutar(AgenteEjecucion $ejecucion): AgenteEjecucion
    {
        // Solo una corrida toma la ejecución: dos workers que coincidan no la duplican.
        $tomada = AgenteEjecucion::where('id', $ejecucion->id)->where('estado', 'en_cola')
            ->update(['estado' => 'corriendo', 'iniciada_at' => now()]);

        if (! $tomada) {
            return $ejecucion->fresh();
        }

        $ejecucion->refresh();
        $agente = AgenteInterno::withTrashed()->with(['cargo.rol', 'sede'])->findOrFail($ejecucion->agente_id);

        $this->orden = 0;

        // Lo que cambia el contexto del proceso se restaura siempre: un worker corre muchas
        // tareas seguidas, y en una corrida «de prueba» lanzada desde la pantalla el proceso es
        // el de la persona que la pidió.
        $previo      = Auth::user();
        $sedePrevia  = session()->get('sede_activa_id');

        try {
            $usuario = $this->prepararUsuario($agente);

            Auth::setUser($usuario);
            app()->forgetInstance('briela.permisos.' . $usuario->id);
            // La sede de quien la pidió no es la del agente.
            session()->forget('sede_activa_id');

            IaService::conContexto('agentes', $agente->id, $ejecucion->id, function () use ($agente, $ejecucion, $usuario) {
                $this->correr($agente, $ejecucion, $usuario);
            });
        } catch (\Throwable $e) {
            $this->fallar($ejecucion, $agente, $e);
        } finally {
            app()->forgetInstance('briela.permisos.' . ($usuario->id ?? 0));
            $previo ? Auth::setUser($previo) : Auth::forgetGuards();

            if ($sedePrevia !== null) {
                session()->put('sede_activa_id', $sedePrevia);
            }
        }

        return $ejecucion->fresh();
    }

    /**
     * El usuario con el que actúa, o un error entendible por qué no puede correr.
     *
     * @throws \RuntimeException
     */
    private function prepararUsuario(AgenteInterno $agente): User
    {
        if (! Modulos::activo('equipo_ia')) {
            throw new \RuntimeException('El módulo Equipo de IA está apagado.');
        }

        if (! $this->ia->configurado()) {
            throw new \RuntimeException('La IA no está configurada en esta instalación.');
        }

        $usuario = $agente->usuario ?? app(AgenteUsuarioService::class)->asegurar($agente);

        if (! $usuario->activo || ! $usuario->rolConfigurable?->activo) {
            // Sin un rol activo `User::permisos()` caería a los permisos del rol legado, que no
            // son los del cargo. Es preferible no correr a correr con otros permisos.
            throw new \RuntimeException('El rol de este agente está inactivo o no existe. Asígnale un cargo con un rol activo.');
        }

        return $usuario;
    }

    private function correr(AgenteInterno $agente, AgenteEjecucion $ejecucion, User $usuario): void
    {
        if (! $agente->activo && ! $ejecucion->prueba) {
            $ejecucion->update(['estado' => 'cancelada', 'terminada_at' => now(), 'error' => 'El agente está apagado.']);

            return;
        }

        $permitidas = CatalogoHerramientas::paraAgente($agente, $usuario);
        $definidas  = CatalogoHerramientas::definiciones($permitidas);

        $modelo    = $this->modeloDe($agente);
        $respaldo  = $agente->modelo_respaldo ?: null;
        $maxPasos  = $agente->maxPasosEfectivo();
        $opciones  = [
            'max_tokens'  => $agente->maxTokensEfectivo(),
            'temperatura' => $agente->temperatura,
        ];

        $tarea = trim((string) ($ejecucion->tarea ?: $agente->tarea)) ?: 'Haz tu revisión habitual y entrega tu resumen.';

        $mensajes = [
            ['role' => 'system', 'content' => $this->prompt->armar($agente)],
            ['role' => 'user', 'content' => $this->prompt->reemplazar($tarea, $this->prompt->variables($agente))],
        ];

        $entrada = 0;
        $salida  = 0;
        $final   = null;
        $usadas  = [];

        for ($vuelta = 1; $vuelta <= $maxPasos; $vuelta++) {
            $r = $this->vuelta($ejecucion, $modelo, $respaldo, $mensajes, $definidas, $opciones);

            $entrada += (int) ($r['usage']['prompt_tokens'] ?? 0);
            $salida  += (int) ($r['usage']['completion_tokens'] ?? 0);

            $texto = trim((string) ($r['mensaje']['content'] ?? ''));

            if (empty($r['tool_calls'])) {
                $this->paso($ejecucion, 'mensaje', null, null, Str::limit($texto, 300), $this->ia->ultimaDuracionMs);
                $final = $texto;
                break;
            }

            $this->paso($ejecucion, 'mensaje', null, null, 'Pidió ' . count($r['tool_calls']) . ' herramienta(s).', $this->ia->ultimaDuracionMs);

            $mensajes[] = ['role' => 'assistant', 'content' => $texto !== '' ? $texto : null, 'tool_calls' => $r['tool_calls']];

            foreach ($r['tool_calls'] as $llamada) {
                $nombre = (string) ($llamada['function']['name'] ?? '');
                $args   = json_decode((string) ($llamada['function']['arguments'] ?? '{}'), true);
                $args   = is_array($args) ? $args : [];
                $usadas[$nombre] = true;

                $t0  = microtime(true);
                $res = $this->herramientas->ejecutar($nombre, $args, $permitidas, $agente, $ejecucion, $ejecucion->prueba);
                $ms  = (int) ((microtime(true) - $t0) * 1000);

                $this->paso($ejecucion, 'herramienta', $nombre, $args, $res['resumen'], $ms);

                $mensajes[] = ['role' => 'tool', 'tool_call_id' => (string) ($llamada['id'] ?? ''), 'content' => $res['json']];
            }

            if (($entrada + $salida) >= $agente->max_tokens_ejecucion) {
                $this->paso($ejecucion, 'error', null, null, 'Se alcanzó el máximo de tokens de la corrida; cierro con lo que hay.');
                break;
            }
        }

        // Sin respuesta final es porque se acabaron los pasos o los tokens: se le pide cerrar,
        // sin herramientas, con lo que ya tiene. Es mejor un resumen parcial que ninguno.
        if ($final === null) {
            $mensajes[] = ['role' => 'user', 'content' => 'Llegaste al límite de pasos. Entrega ahora tu resumen final con lo que ya sabes, y di qué faltó revisar.'];

            $r = $this->vuelta($ejecucion, $modelo, $respaldo, $mensajes, [], $opciones);

            $entrada += (int) ($r['usage']['prompt_tokens'] ?? 0);
            $salida  += (int) ($r['usage']['completion_tokens'] ?? 0);

            $final = trim((string) ($r['mensaje']['content'] ?? ''));
            $this->paso($ejecucion, 'mensaje', null, null, Str::limit($final, 300), $this->ia->ultimaDuracionMs);
        }

        if ($final === '') {
            throw new \RuntimeException("El modelo {$ejecucion->fresh()->modelo_usado} no devolvió texto. Prueba con otro modelo en la configuración del agente.");
        }

        $propuestas = AgentePropuesta::where('ejecucion_id', $ejecucion->id)->count();

        $ejecucion->update([
            'estado'         => 'terminada',
            'resumen'        => Str::limit($final, 6000, ''),
            'resultado'      => ['propuestas' => $propuestas, 'herramientas' => array_keys($usadas)],
            'tokens_entrada' => $entrada,
            'tokens_salida'  => $salida,
            'terminada_at'   => now(),
        ]);

        if (! $ejecucion->prueba) {
            $agente->forceFill(['ultima_ejecucion_at' => now()])->save();
            $this->avisar($agente, $ejecucion->fresh(), $propuestas);
        }
    }

    /**
     * Una vuelta con el modelo, con respaldo: si el principal falla por error del proveedor o
     * por tiempo, se reintenta UNA vez con el de respaldo. Un 402 (suscripción o cupo) no se
     * reintenta: cambiar de modelo no lo arregla y gastaría otro intento.
     */
    private function vuelta(AgenteEjecucion $ejecucion, string &$modelo, ?string $respaldo, array $mensajes, array $definidas, array $opciones): array
    {
        try {
            $r = $this->ia->chat($mensajes, $definidas, ['modelo' => $modelo] + $opciones);
        } catch (IaException $e) {
            if (! $e->admiteRespaldo() || ! $respaldo || $respaldo === $modelo) {
                throw $e;
            }

            $this->paso($ejecucion, 'error', null, null, "Falló {$modelo}: " . Str::limit($e->getMessage(), 160) . " Reintento con {$respaldo}.");

            $modelo = $respaldo;
            $r      = $this->ia->chat($mensajes, $definidas, ['modelo' => $modelo] + $opciones);
        }

        $ejecucion->update(['modelo_usado' => $r['modelo'] ?? $modelo, 'pasos' => $this->orden]);

        return $r;
    }

    /**
     * El modelo del agente: el suyo, o el del nivel (económico = el rápido de la instalación,
     * potente = el de texto). Ver `RecomendadorModelo` para cómo se elige al configurar.
     */
    public function modeloDe(AgenteInterno $agente): string
    {
        if (filled($agente->modelo)) {
            return (string) $agente->modelo;
        }

        return $agente->nivelEfectivo() === 'potente' ? $this->ia->modeloTexto() : $this->ia->modeloRapido();
    }

    private function paso(AgenteEjecucion $e, string $tipo, ?string $herramienta, ?array $args, ?string $resumen, int $ms = 0): void
    {
        AgentePaso::create([
            'ejecucion_id' => $e->id,
            'orden'        => ++$this->orden,
            'tipo'         => $tipo,
            'herramienta'  => $herramienta,
            'argumentos'   => $args ? CatalogoHerramientas::limpiar($args) : null,
            'resumen'      => $resumen,
            'ms'           => $ms,
            'created_at'   => now(),
        ]);
    }

    private function fallar(AgenteEjecucion $ejecucion, AgenteInterno $agente, \Throwable $e): void
    {
        // Los errores de la IA ya vienen redactados para una persona; los demás se reportan al
        // log y a la pantalla se le da algo corto, sin rutas ni consultas.
        $mensaje = ($e instanceof IaException || $e instanceof \RuntimeException)
            ? $e->getMessage()
            : 'Error interno al correr el agente. Quedó registrado en el log.';

        if (! ($e instanceof IaException || $e instanceof \RuntimeException)) {
            report($e);
        }

        $this->paso($ejecucion, 'error', null, null, Str::limit($mensaje, 300));

        $ejecucion->update(['estado' => 'fallida', 'error' => Str::limit($mensaje, 1000, ''), 'terminada_at' => now()]);

        if (! $ejecucion->prueba) {
            $this->notificar($this->destinatarios($agente, 'equipo_ia.gestionar'), 'agente_fallo', "{$agente->nombre} no pudo terminar", Str::limit($mensaje, 200));
        }
    }

    private function avisar(AgenteInterno $agente, AgenteEjecucion $ejecucion, int $propuestas): void
    {
        if ($propuestas > 0) {
            $this->notificar(
                $this->destinatarios($agente, 'equipo_ia.aprobar'),
                'agente_propuesta',
                $propuestas === 1 ? "{$agente->nombre} dejó 1 propuesta por aprobar" : "{$agente->nombre} dejó {$propuestas} propuestas por aprobar",
                'Revísalas antes de que venzan.',
            );

            return;
        }

        $this->notificar(
            $this->destinatarios($agente, 'equipo_ia.ver'),
            'agente_resumen',
            "{$agente->nombre}: resumen listo",
            Str::limit((string) $ejecucion->resumen, 200),
        );
    }

    /**
     * A quién se avisa: los que el agente nombra, o todos los que tengan el permiso. Quien
     * aparece en `avisar_a` sin el permiso de ver no recibe nada: un aviso a una pantalla que
     * no puede abrir es ruido.
     *
     * @return list<int>
     */
    private function destinatarios(AgenteInterno $agente, string $permiso): array
    {
        $nombrados = array_filter(array_map('intval', $agente->avisar_a ?? []));

        return User::where('activo', true)
            ->when($nombrados !== [], fn ($q) => $q->whereIn('id', $nombrados))
            ->get()
            ->filter(fn (User $u) => $u->tienePermiso($permiso))
            ->pluck('id')
            ->all();
    }

    /** @param list<int> $ids */
    private function notificar(array $ids, string $tipo, string $titulo, ?string $mensaje): void
    {
        $servicio = app(NotificacionService::class);

        foreach ($ids as $id) {
            $servicio->crear($id, $tipo, $titulo, $mensaje, '/configuracion/agentes?equipo=1');
        }
    }
}
