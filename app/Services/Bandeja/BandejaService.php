<?php

namespace App\Services\Bandeja;

use App\Models\BandejaConversacion;
use App\Models\User;
use App\Models\WhatsappConversacion;
use App\Support\Canales;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as ConsultaCruda;
use Illuminate\Support\Facades\DB;

/**
 * La bandeja: una sola lista con lo que entra por WhatsApp y por las redes.
 *
 * **Por qué la lista se arma acá y no en los adaptadores.** Una lista unificada es, por
 * naturaleza, UNA consulta: si cada canal trajera la suya y se mezclaran en PHP, la segunda
 * página saldría mal —habría que traer de más de cada lado para poder cortar— y una empresa con
 * cinco mil conversaciones pagaría el recorrido completo en cada apertura. Así que esto hace un
 * `UNION` de las dos tablas, normaliza las columnas en el `SELECT`, y pagina sobre eso. Dos
 * tablas, una lista, tres consultas.
 *
 * Lo que sí vive en cada adaptador es lo que de verdad es distinto por canal: cómo se manda un
 * mensaje y qué reglas tiene (`CanalBandeja`). Ver `Canales` para esas reglas.
 *
 * El precio de normalizar en SQL es que los nombres de columna de las dos tablas tienen que
 * caber en una sola forma; el nombre de esa forma es lo que la pantalla recibe, y es lo único
 * que la pantalla conoce.
 */
class BandejaService
{
    public const POR_PAGINA = 25;

    /** @param  iterable<CanalBandeja>  $canales */
    public function __construct(private readonly iterable $canales)
    {
    }

    // ─── La lista ────────────────────────────────────────────────────────────

    /**
     * Las conversaciones, ya mezcladas y paginadas.
     *
     * @param  array{canal?: ?string, buscar?: ?string, estado?: ?string, asignado?: ?string}  $filtros
     */
    public function lista(array $filtros, User $usuario): LengthAwarePaginator
    {
        $consulta = DB::query()
            ->fromSub($this->union($filtros, $usuario), 'conv')
            // Lo último que se movió primero: una bandeja ordenada de otra forma no es una
            // bandeja. Desempate por canal e id, o la paginación repite filas.
            ->orderByDesc('ultimo_mensaje_at')
            ->orderByDesc('id')
            ->orderBy('canal');

        $pagina = $consulta->paginate(self::POR_PAGINA)->withQueryString();

        return $pagina->through(fn ($fila) => $this->presentar($fila));
    }

    /**
     * Los contadores de los filtros: cuántas sin leer, cuántas mías, cuántas sin dueño.
     *
     * Van en la misma petición que la lista a propósito. Pedirlos aparte sería una segunda
     * consulta por cada apertura de la bandeja, que es justo lo que la regla de velocidad
     * prohíbe (ver «Velocidad: nada cuesta una petición por clic»).
     *
     * @return array{total: int, sin_leer: int, mias: int, sin_asignar: int}
     */
    public function contadores(User $usuario): array
    {
        $fila = DB::query()
            ->fromSub($this->union(['estado' => 'activas'], $usuario), 'conv')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN leido = 0 THEN 1 ELSE 0 END) as sin_leer')
            ->selectRaw('SUM(CASE WHEN asignado_a = ? THEN 1 ELSE 0 END) as mias', [$usuario->id])
            ->selectRaw('SUM(CASE WHEN asignado_a IS NULL THEN 1 ELSE 0 END) as sin_asignar')
            ->first();

        return [
            'total'       => (int) ($fila->total ?? 0),
            'sin_leer'    => (int) ($fila->sin_leer ?? 0),
            'mias'        => (int) ($fila->mias ?? 0),
            'sin_asignar' => (int) ($fila->sin_asignar ?? 0),
        ];
    }

    /**
     * Cuántas conversaciones sin leer le tocan a esta persona.
     *
     * Es el número del menú, y lo pide `useAvisos` junto con lo demás: una consulta, no una
     * petición nueva. «Le tocan» quiere decir las suyas más las que no son de nadie; las de
     * otro asesor no son su pendiente.
     */
    public function sinLeerPara(User $usuario): int
    {
        return (int) DB::query()
            ->fromSub($this->union(['estado' => 'sin_leer'], $usuario), 'conv')
            ->whereNull('asignado_a')
            ->orWhere('asignado_a', $usuario->id)
            ->count();
    }

    /**
     * El `UNION` de las dos tablas con las columnas ya normalizadas.
     *
     * Los canales de módulos apagados no entran: ni en la lista, ni en los contadores. Si no
     * queda ninguno —la bandeja apagada— se devuelve una consulta que no da filas, en vez de
     * un `UNION` vacío, que en MySQL no es SQL válido.
     */
    private function union(array $filtros, User $usuario): ConsultaCruda
    {
        $activos = array_keys(Canales::activos());
        $canal   = $filtros['canal'] ?? null;

        if ($canal && Canales::existe($canal)) {
            $activos = array_intersect($activos, [$canal]);
        }

        $partes = [];

        if (in_array('whatsapp', $activos, true)) {
            $partes[] = $this->consultaWhatsapp($filtros);
        }

        $deRedes = array_values(array_intersect($activos, Canales::deRedes()));

        if ($deRedes !== []) {
            $partes[] = $this->consultaRedes($filtros, $deRedes);
        }

        if ($partes === []) {
            return DB::table('whatsapp_conversaciones')->whereRaw('1 = 0')
                ->selectRaw($this->columnasVacias());
        }

        $union = array_shift($partes);

        foreach ($partes as $parte) {
            $union->unionAll($parte);
        }

        return $union;
    }

    private function consultaWhatsapp(array $filtros): ConsultaCruda
    {
        $consulta = DB::table('whatsapp_conversaciones as c')
            ->selectRaw("'whatsapp' as canal")
            ->selectRaw('c.id as id')
            ->selectRaw('COALESCE(NULLIF(c.nombre_contacto, ""), c.numero_contacto) as contacto')
            ->selectRaw('c.numero_contacto as handle')
            ->selectRaw('n.nombre as origen')
            ->selectRaw('c.ultimo_mensaje_at, c.ultimo_entrante_at, c.leido, c.archivada_at')
            ->selectRaw('c.asignado_a, c.crm_lead_id, c.cliente_id, c.escalada_at')
            ->selectRaw('(SELECT m.contenido FROM whatsapp_mensajes m WHERE m.whatsapp_conversacion_id = c.id ORDER BY m.id DESC LIMIT 1) as ultimo_texto')
            ->selectRaw('(SELECT m.direccion FROM whatsapp_mensajes m WHERE m.whatsapp_conversacion_id = c.id ORDER BY m.id DESC LIMIT 1) as ultima_direccion')
            ->leftJoin('whatsapp_numeros as n', 'n.id', '=', 'c.whatsapp_numero_id');

        $this->comunes($consulta, $filtros, ['c.nombre_contacto', 'c.numero_contacto']);

        return $consulta;
    }

    private function consultaRedes(array $filtros, array $canales): ConsultaCruda
    {
        $consulta = DB::table('bandeja_conversaciones as c')
            ->selectRaw('c.canal as canal')
            ->selectRaw('c.id as id')
            ->selectRaw('COALESCE(NULLIF(c.nombre_contacto, ""), NULLIF(c.usuario_externo, ""), "Sin nombre") as contacto')
            ->selectRaw('c.usuario_externo as handle')
            ->selectRaw('r.nombre_cuenta as origen')
            ->selectRaw('c.ultimo_mensaje_at, c.ultimo_entrante_at, c.leido, c.archivada_at')
            ->selectRaw('c.asignado_a, c.crm_lead_id, c.cliente_id, c.escalada_at')
            ->selectRaw('(SELECT m.contenido FROM bandeja_mensajes m WHERE m.bandeja_conversacion_id = c.id ORDER BY m.id DESC LIMIT 1) as ultimo_texto')
            ->selectRaw('(SELECT m.direccion FROM bandeja_mensajes m WHERE m.bandeja_conversacion_id = c.id ORDER BY m.id DESC LIMIT 1) as ultima_direccion')
            ->leftJoin('cuentas_rrss as r', 'r.id', '=', 'c.cuenta_rrss_id')
            ->whereIn('c.canal', $canales);

        $this->comunes($consulta, $filtros, ['c.nombre_contacto', 'c.usuario_externo']);

        return $consulta;
    }

    /**
     * Los filtros que valen igual en las dos tablas.
     *
     * @param  array<int, string>  $camposBusqueda  dónde buscar el texto en esta tabla
     */
    private function comunes(ConsultaCruda $consulta, array $filtros, array $camposBusqueda): void
    {
        $estado = $filtros['estado'] ?? 'activas';

        match ($estado) {
            'archivadas' => $consulta->whereNotNull('c.archivada_at'),
            'sin_leer'   => $consulta->whereNull('c.archivada_at')->where('c.leido', false),
            'todas'      => null,
            default      => $consulta->whereNull('c.archivada_at'),
        };

        if ($asignado = ($filtros['asignado'] ?? null)) {
            // «sin_asignar» es su propio filtro y no un id: una conversación sin dueño es
            // precisamente lo que alguien busca cuando entra a ver qué falta atender.
            $asignado === 'sin_asignar'
                ? $consulta->whereNull('c.asignado_a')
                : $consulta->where('c.asignado_a', (int) $asignado);
        }

        if ($buscar = trim((string) ($filtros['buscar'] ?? ''))) {
            $consulta->where(function ($q) use ($camposBusqueda, $buscar) {
                foreach ($camposBusqueda as $campo) {
                    $q->orWhere($campo, 'like', '%' . $buscar . '%');
                }
            });
        }
    }

    /** Las mismas columnas del `UNION`, en nulo: para la consulta que no devuelve nada. */
    private function columnasVacias(): string
    {
        return "'' as canal, 0 as id, '' as contacto, '' as handle, '' as origen, "
            . 'NULL as ultimo_mensaje_at, NULL as ultimo_entrante_at, 1 as leido, NULL as archivada_at, '
            . "NULL as asignado_a, NULL as crm_lead_id, NULL as cliente_id, NULL as escalada_at, '' as ultimo_texto, '' as ultima_direccion";
    }

    /**
     * Una fila del `UNION`, como la quiere la pantalla.
     *
     * El estado de la ventana se calcula acá y no en el modelo: la lista no hidrata modelos
     * —son dos tablas distintas— y pintar «puedes responder» en veinticinco filas no vale
     * veinticinco consultas.
     */
    private function presentar(object $fila): array
    {
        $config  = Canales::de($fila->canal) ?? [];
        $horas   = $config['ventana'] ?? null;
        $entrante = $fila->ultimo_entrante_at ? \Illuminate\Support\Carbon::parse($fila->ultimo_entrante_at) : null;

        return [
            'clave'      => $fila->canal . ':' . $fila->id,
            'canal'      => $fila->canal,
            'canal_label'=> Canales::label($fila->canal),
            'icono'      => $config['icono'] ?? 'comment',
            'color'      => $config['color'] ?? 'azul',
            'id'         => (int) $fila->id,
            'contacto'   => $fila->contacto,
            'handle'     => $fila->handle,
            'origen'     => $fila->origen,
            'ultimo_texto'     => $this->resumir($fila->ultimo_texto, $fila->ultima_direccion),
            'ultimo_mensaje_at'=> $fila->ultimo_mensaje_at,
            'sin_leer'   => ! $fila->leido,
            'archivada'  => $fila->archivada_at !== null,
            'asignado_a' => $fila->asignado_a ? (int) $fila->asignado_a : null,
            'lead_id'    => $fila->crm_lead_id ? (int) $fila->crm_lead_id : null,
            'cliente_id' => $fila->cliente_id ? (int) $fila->cliente_id : null,
            'escalada'   => $fila->escalada_at !== null,
            'ventana_abierta' => $horas === null || ($entrante && $entrante->greaterThan(now()->subHours($horas))),
        ];
    }

    /**
     * El renglón de vista previa.
     *
     * Un mensaje nuestro lleva «Tú:» delante. Sin eso, la lista no distingue entre «el cliente
     * preguntó» y «ya le contestamos», que es lo primero que uno mira para saber qué falta.
     */
    private function resumir(?string $texto, ?string $direccion): string
    {
        $texto = trim((string) $texto);

        if ($texto === '') {
            return '📎 Archivo adjunto';
        }

        $texto = \Illuminate\Support\Str::limit(preg_replace('/\s+/', ' ', $texto), 90);

        return $direccion === 'saliente' ? 'Tú: ' . $texto : $texto;
    }

    // ─── Una conversación ────────────────────────────────────────────────────

    /**
     * El adaptador que atiende un canal.
     *
     * @throws \InvalidArgumentException si el canal no existe o su módulo está apagado
     */
    public function canal(string $canal): CanalBandeja
    {
        if (! Canales::activo($canal)) {
            throw new \InvalidArgumentException("El canal «{$canal}» no está disponible en esta instalación.");
        }

        foreach ($this->canales as $adaptador) {
            if (in_array($canal, $adaptador->canales(), true)) {
                return $adaptador;
            }
        }

        throw new \InvalidArgumentException("No hay quien atienda el canal «{$canal}».");
    }

    /**
     * La conversación que nombra una clave «canal:id».
     *
     * La clave llega del navegador, así que el canal se valida contra el catálogo antes de
     * tocar la base: sin eso, `canal:id` sería una forma de pedir cualquier tabla.
     */
    public function resolver(string $clave): Model
    {
        [$canal, $id] = array_pad(explode(':', $clave, 2), 2, null);

        // Un canal que no existe —o que está apagado en esta instalación— es un 404, no un
        // error del sistema: lo escribió quien tecleó la URL. `canal()` sí lanza, porque ahí
        // un canal desconocido significa que falta registrar un adaptador, y eso es un error
        // nuestro que hay que ver.
        if (! $canal || ! ctype_digit((string) $id) || ! Canales::activo($canal)) {
            abort(404);
        }

        $conversacion = $this->canal($canal)->encontrar($canal, (int) $id);

        abort_if($conversacion === null, 404);

        return $conversacion;
    }

    /** Marca leída una conversación. Solo escribe si hacía falta. */
    public function marcarLeida(Model $conversacion): void
    {
        if (! $conversacion->leido) {
            $conversacion->forceFill(['leido' => true])->save();
        }
    }

    /**
     * Pone (o quita) dueño.
     *
     * Asignarse una conversación **la saca del agente de IA**: si una persona se hizo cargo, dos
     * voces en el mismo chat son peores que ninguna. Es la misma regla que ya aplicaba
     * `AgenteConversacionService` cuando el lead tenía responsable.
     */
    public function asignar(Model $conversacion, ?int $usuarioId): void
    {
        $cambios = ['asignado_a' => $usuarioId];

        if ($usuarioId !== null && $conversacion->escalada_at === null) {
            $cambios['escalada_at'] = now();
        }

        $conversacion->forceFill($cambios)->save();
    }

    public function archivar(Model $conversacion, bool $archivar = true): void
    {
        $conversacion->forceFill([
            'archivada_at' => $archivar ? now() : null,
            // Archivar es «ya la atendí»: dejarla sin leer la haría contar en el menú para
            // siempre, en una lista que nadie vuelve a abrir.
            'leido'        => $archivar ? true : $conversacion->leido,
        ])->save();
    }

    /**
     * El modelo correcto para una clave de canal, sin pasar por el adaptador.
     * Lo usan los webhooks, que ya saben en qué tabla están escribiendo.
     */
    public static function modeloDe(string $canal): string
    {
        return $canal === 'whatsapp' ? WhatsappConversacion::class : BandejaConversacion::class;
    }

    /**
     * Guarda el archivo que alguien adjuntó desde la bandeja.
     *
     * Queda colgado de su conversación por el morph y con `categoria='bandeja'`, igual que lo
     * que entra: así la ficha del cliente y Multimedia muestran la conversación completa, no
     * solo la mitad que mandó él.
     */
    public function guardarAdjunto(Model $conversacion, \Illuminate\Http\UploadedFile $archivo, User $quien): \App\Models\Archivo
    {
        $resultado = \App\Services\ArchivoServidorService::subir($archivo, 'bandeja/' . now()->format('Y-m'));

        return \App\Models\Archivo::create([
            'nombre_original' => $archivo->getClientOriginalName(),
            'nombre_archivo'  => $resultado['name'],
            'ruta'            => $resultado['ruta'],
            'storage'         => 'local',
            'tipo_mime'       => $archivo->getMimeType(),
            'extension'       => strtolower($archivo->getClientOriginalExtension() ?: 'bin'),
            'tamano'          => $archivo->getSize(),
            'categoria'       => 'bandeja',
            'archivable_type' => $conversacion->getMorphClass(),
            'archivable_id'   => $conversacion->getKey(),
            'subido_por'      => $quien->id,
        ]);
    }
}
