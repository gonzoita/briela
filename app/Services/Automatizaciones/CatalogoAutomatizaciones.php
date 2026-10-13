<?php

namespace App\Services\Automatizaciones;

use App\Support\Modulos;
use Illuminate\Database\Eloquent\Model;

/**
 * Todo lo que una automatización puede escuchar y hacer: el catálogo CERRADO.
 *
 * La pantalla combina estas piezas; no inventa otras. Una capacidad nueva es una pieza nueva que
 * llega con una actualización a todas las instalaciones, y por eso una automatización puede ser
 * muy configurable sin que nadie parchee el código de un cliente.
 *
 * Los documentos y sus datos están en `EntidadesAutomatizacion`; aquí están los eventos (qué le
 * puede pasar a cada documento) y las acciones (qué se puede hacer).
 *
 * **Hay cosas que ninguna automatización hace, a propósito:** escribir a clientes o publicar en
 * redes, registrar pagos, cambiar el estado de una OP o de una remisión, despachar, borrar y cambiar
 * precios. Un error ahí es caro o público, y una automatización actúa sin nadie mirando. Cuando hace
 * falta algo de eso, el camino es un paso de **aprobación** seguido de un aviso a quien lo hace, o un
 * agente del Equipo de IA, que propone y espera.
 */
class CatalogoAutomatizaciones
{
    // ─── Eventos ─────────────────────────────────────────────────────────────

    /** @return array<string, array{label: string, entidad: string}> */
    private static function definicionEventos(): array
    {
        return [
            'cotizacion.estado'       => ['label' => 'Cambia el estado de una cotización',                 'entidad' => 'cotizacion'],
            'lead.nuevo'              => ['label' => 'Llega un lead nuevo',                                'entidad' => 'lead'],
            'lead.etapa'              => ['label' => 'Un lead cambia de etapa',                            'entidad' => 'lead'],
            'lead.cerrado'            => ['label' => 'Un lead se gana o se pierde',                        'entidad' => 'lead'],
            'cliente.nuevo'           => ['label' => 'Se crea un cliente',                                 'entidad' => 'cliente'],
            'op.estado'               => ['label' => 'Cambia el estado de una orden de producción',        'entidad' => 'op'],
            'unidad.terminada'        => ['label' => 'Una unidad termina en planta',                       'entidad' => 'unidad'],
            'unidad.firmada'          => ['label' => 'Calidad firma una unidad',                           'entidad' => 'unidad'],
            'remision.estado'         => ['label' => 'Cambia el estado de una remisión',                   'entidad' => 'remision'],
            'pago.registrado'         => ['label' => 'Se registra un pago de una orden',                   'entidad' => 'pago'],
            'pago.verificado'         => ['label' => 'Se verifica un pago',                                'entidad' => 'pago'],
            'stock.bajo'              => ['label' => 'Un producto baja de su stock mínimo',                'entidad' => 'producto'],
            'solicitud_compra.estado' => ['label' => 'Cambia el estado de una solicitud de compra',        'entidad' => 'solicitud_compra'],
            'orden_compra.estado'     => ['label' => 'Cambia el estado de una orden de compra',            'entidad' => 'orden_compra'],
            'mantenimiento.estado'    => ['label' => 'Cambia el estado de un mantenimiento',               'entidad' => 'mantenimiento'],
            'permiso.solicitado'      => ['label' => 'Un colaborador pide un permiso',                     'entidad' => 'permiso_colaborador'],
            'inscripcion.estado'      => ['label' => 'Avanza o termina una inscripción a un curso',        'entidad' => 'inscripcion'],
            'mensaje.entrante'        => ['label' => 'Llega un mensaje a la bandeja (WhatsApp o redes)',   'entidad' => 'conversacion'],
            'propuesta.nueva'         => ['label' => 'Un agente del Equipo de IA deja una propuesta',      'entidad' => 'propuesta'],
        ];
    }

    /**
     * Qué puede pasar para que una automatización arranque. `campos` es lo que las condiciones
     * pueden mirar; `ejemplo` alimenta la prueba en seco.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function eventos(): array
    {
        $out = [];

        foreach (self::definicionEventos() as $clave => $e) {
            $ent = EntidadesAutomatizacion::def($e['entidad']);

            $out[$clave] = $e + [
                'modulo'  => $ent['modulo'],
                'campos'  => self::camposDe($e['entidad']),
                'ejemplo' => self::ejemploDe($e['entidad']),
            ];
        }

        return $out;
    }

    /** Los eventos que ofrece ESTA instalación: los de módulos apagados no se ofrecen. */
    public static function eventosDisponibles(): array
    {
        return array_filter(self::eventos(), fn ($e) => EntidadesAutomatizacion::disponible($e['entidad']));
    }

    /** Los documentos que se pueden barrer en un horario («cada mañana, cada cotización que…»). */
    public static function barridosDisponibles(): array
    {
        return array_filter(
            EntidadesAutomatizacion::todas(),
            fn ($d, $k) => $d['barrido'] !== null && EntidadesAutomatizacion::disponible($k),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    // ─── Los datos que miran las condiciones ─────────────────────────────────

    /**
     * Los datos que hay siempre, sea cual sea el documento: la hora de la corrida y lo que los
     * pasos de IA van dejando. Sirven para «solo en horario laboral» o «si la IA dijo que es urgente».
     */
    public static function camposGlobales(): array
    {
        return [
            'hora'         => ['label' => 'Hora del día (0-23)', 'tipo' => 'numero', 'global' => true],
            'dia_semana'   => ['label' => 'Día de la semana', 'tipo' => 'opciones', 'opciones' => ['lunes', 'martes', 'miercoles', 'jueves', 'viernes', 'sabado', 'domingo'], 'global' => true],
            'texto_ia'     => ['label' => 'Lo que redactó la IA', 'tipo' => 'texto', 'global' => true, 'variable' => true],
            'categoria_ia' => ['label' => 'Lo que clasificó la IA', 'tipo' => 'texto', 'global' => true, 'variable' => true],
        ];
    }

    /** Los campos del documento más los globales. */
    public static function camposDe(?string $entidad): array
    {
        $propios = $entidad ? (EntidadesAutomatizacion::def($entidad)['campos'] ?? []) : [];

        return $propios + self::camposGlobales();
    }

    public static function ejemploDe(?string $entidad): array
    {
        return ($entidad ? (EntidadesAutomatizacion::def($entidad)['ejemplo'] ?? []) : []) + self::globales();
    }

    /** Los valores de los datos globales en este momento. */
    public static function globales(): array
    {
        $dias = [1 => 'lunes', 'martes', 'miercoles', 'jueves', 'viernes', 'sabado', 'domingo'];

        return ['hora' => (int) now()->format('G'), 'dia_semana' => $dias[now()->dayOfWeekIso], 'hoy' => now()->toDateString()];
    }

    // ─── Acciones ────────────────────────────────────────────────────────────

    /**
     * @return array<string, array{label: string, grupo: string, permiso: ?string, modulo: ?string, entidades: ?list<string>,
     *   sin_sensibles: bool, descripcion: string, campos: array<string, array<string, mixed>>}>
     *   `entidades`: de qué documentos puede colgar (null = de cualquiera, o sin documento).
     *   `sin_sensibles`: no se puede usar sobre datos de colaboradores (los manda fuera o a la IA).
     */
    public static function acciones(): array
    {
        $destinos = ['responsable' => 'El responsable del documento', 'usuarios' => 'Personas que elijo', 'permiso' => 'Quienes tengan un permiso', 'autor' => 'A mí (quien la armó)'];

        return [
            'notificar' => [
                'label' => 'Avisar a alguien', 'grupo' => 'Avisos', 'permiso' => null, 'modulo' => null, 'entidades' => null, 'sin_sensibles' => false,
                'descripcion' => 'Pone un aviso en la campana (y por correo si esa persona lo tiene activado), con enlace al documento.',
                'campos' => [
                    'destinatario' => ['label' => 'A quién', 'tipo' => 'opciones', 'opciones' => array_keys($destinos), 'etiquetas' => $destinos, 'requerido' => true],
                    'usuarios'     => ['label' => 'Personas', 'tipo' => 'usuarios', 'si' => ['destinatario' => 'usuarios']],
                    'permiso'      => ['label' => 'Quienes pueden…', 'tipo' => 'permiso', 'si' => ['destinatario' => 'permiso']],
                    'titulo'       => ['label' => 'Título', 'tipo' => 'texto', 'requerido' => true],
                    'mensaje'      => ['label' => 'Mensaje', 'tipo' => 'texto_largo'],
                ],
            ],
            'comentar_documento' => [
                'label' => 'Dejar un comentario o tarea en el documento', 'grupo' => 'Avisos', 'permiso' => null, 'modulo' => null,
                'entidades' => ['op', 'cotizacion', 'cliente', 'orden_compra'], 'sin_sensibles' => false,
                'descripcion' => 'Aparece en los comentarios del documento. Como tarea, le queda pendiente al responsable hasta que la resuelva.',
                'campos' => [
                    'tipo'     => ['label' => 'Tipo', 'tipo' => 'opciones', 'opciones' => ['comentario', 'tarea'], 'etiquetas' => ['comentario' => 'Comentario', 'tarea' => 'Tarea para el responsable'], 'requerido' => true],
                    'texto'    => ['label' => 'Texto', 'tipo' => 'texto_largo', 'requerido' => true],
                    'dias'     => ['label' => 'Plazo de la tarea (días)', 'tipo' => 'entero', 'si' => ['tipo' => 'tarea']],
                ],
            ],
            'crear_tarea_crm' => [
                'label' => 'Crear una tarea en el lead', 'grupo' => 'Comercial', 'permiso' => 'crm.editar', 'modulo' => 'crm',
                'entidades' => ['lead', 'cotizacion', 'tarea_crm', 'conversacion'], 'sin_sensibles' => false,
                'descripcion' => 'Agrega una tarea de seguimiento al lead (o al lead de la cotización o de la conversación).',
                'campos' => [
                    'titulo'      => ['label' => 'Título', 'tipo' => 'texto', 'requerido' => true],
                    'descripcion' => ['label' => 'Detalle', 'tipo' => 'texto_largo'],
                    'tipo'        => ['label' => 'Tipo', 'tipo' => 'opciones', 'opciones' => ['seguimiento', 'llamada', 'email', 'reunion', 'otro'], 'etiquetas' => ['seguimiento' => 'Seguimiento', 'llamada' => 'Llamada', 'email' => 'Correo', 'reunion' => 'Reunión', 'otro' => 'Otro']],
                    'dias'        => ['label' => 'Vence en (días)', 'tipo' => 'entero'],
                ],
            ],
            'nota_cotizacion' => [
                'label' => 'Agregar una nota de seguimiento a la cotización', 'grupo' => 'Comercial', 'permiso' => 'cotizaciones.editar', 'modulo' => 'cotizaciones',
                'entidades' => ['cotizacion'], 'sin_sensibles' => false,
                'descripcion' => 'Queda en el historial de seguimiento de la cotización.',
                'campos' => [
                    'texto' => ['label' => 'Nota', 'tipo' => 'texto_largo', 'requerido' => true],
                ],
            ],
            'mover_lead' => [
                'label' => 'Mover el lead a otra etapa', 'grupo' => 'Comercial', 'permiso' => 'crm.editar', 'modulo' => 'crm',
                'entidades' => ['lead', 'cotizacion', 'conversacion'], 'sin_sensibles' => false,
                'descripcion' => 'Solo a etapas abiertas: ganar o perder un lead lo decide una persona (o la cotización, que ya lo hace sola).',
                'campos' => [
                    'etapa_id' => ['label' => 'Etapa', 'tipo' => 'etapa', 'requerido' => true],
                ],
            ],
            'asignar_lead' => [
                'label' => 'Asignar el lead a un vendedor', 'grupo' => 'Comercial', 'permiso' => 'crm.editar', 'modulo' => 'crm',
                'entidades' => ['lead', 'conversacion'], 'sin_sensibles' => false,
                'descripcion' => 'Si eliges varias personas, se reparte al que tenga menos leads activos. No le quita el lead a quien ya lo tiene.',
                'campos' => [
                    'usuarios' => ['label' => 'Entre quiénes', 'tipo' => 'usuarios', 'requerido' => true],
                ],
            ],
            'asignar_conversacion' => [
                'label' => 'Asignar la conversación a alguien', 'grupo' => 'Comercial', 'permiso' => 'bandeja.asignar', 'modulo' => 'bandeja',
                'entidades' => ['conversacion'], 'sin_sensibles' => false,
                'descripcion' => 'Si eliges varias personas, se reparte al que tenga menos conversaciones abiertas. No se la quita a quien ya la tiene.',
                'campos' => [
                    'usuarios' => ['label' => 'Entre quiénes', 'tipo' => 'usuarios', 'requerido' => true],
                ],
            ],
            'crear_solicitud_compra' => [
                'label' => 'Crear una solicitud de compra del faltante', 'grupo' => 'Inventario y compras', 'permiso' => 'solicitudes.crear', 'modulo' => 'compras',
                'entidades' => ['producto'], 'sin_sensibles' => false,
                'descripcion' => 'Queda en borrador para que compras la revise. Si el producto ya tiene una solicitud abierta, no crea otra.',
                'campos' => [
                    'multiplicador' => ['label' => 'Pedir el faltante × (1 = solo lo que falta para el mínimo)', 'tipo' => 'entero'],
                    'motivo'        => ['label' => 'Motivo', 'tipo' => 'texto'],
                ],
            ],
            'redactar_ia' => [
                'label' => 'Redactar un texto con IA', 'grupo' => 'Inteligencia artificial', 'permiso' => null, 'modulo' => 'equipo_ia',
                'entidades' => null, 'sin_sensibles' => true,
                'descripcion' => 'La IA escribe con los datos del documento (nunca costos ni datos de colaboradores). El texto queda en {{texto_ia}} para los pasos siguientes. Gasta del cupo del Equipo de IA.',
                'campos' => [
                    'instrucciones' => ['label' => 'Qué debe escribir', 'tipo' => 'texto_largo', 'requerido' => true],
                ],
            ],
            'clasificar_ia' => [
                'label' => 'Clasificar con IA', 'grupo' => 'Inteligencia artificial', 'permiso' => null, 'modulo' => 'equipo_ia',
                'entidades' => null, 'sin_sensibles' => true,
                'descripcion' => 'La IA elige UNA de tus categorías (o «otra»). Queda en {{categoria_ia}} y una condición posterior puede decidir con ella. Gasta del cupo del Equipo de IA.',
                'campos' => [
                    'pregunta'   => ['label' => 'Qué debe decidir', 'tipo' => 'texto', 'requerido' => true],
                    'categorias' => ['label' => 'Categorías, separadas por coma', 'tipo' => 'texto', 'requerido' => true],
                ],
            ],
            'ejecutar_agente' => [
                'label' => 'Poner a trabajar a un agente', 'grupo' => 'Inteligencia artificial', 'permiso' => 'equipo_ia.ejecutar', 'modulo' => 'equipo_ia',
                'entidades' => null, 'sin_sensibles' => true,
                'descripcion' => 'Lanza una corrida de un agente del Equipo de IA con una tarea sobre este documento. Sus propuestas esperan aprobación como siempre.',
                'campos' => [
                    'agente_id' => ['label' => 'Agente', 'tipo' => 'agente', 'requerido' => true],
                    'tarea'     => ['label' => 'Tarea (vacío = la habitual)', 'tipo' => 'texto_largo'],
                ],
            ],
            'webhook' => [
                'label' => 'Enviar a otro sistema (webhook)', 'grupo' => 'Integraciones', 'permiso' => 'configuracion.editar', 'modulo' => null,
                'entidades' => null, 'sin_sensibles' => true,
                'descripcion' => 'Manda los datos del documento en JSON (POST) a una dirección https: Zapier, Make, n8n o tu propio sistema. Nunca manda costos ni datos de colaboradores.',
                'campos' => [
                    'url'   => ['label' => 'Dirección (https://…)', 'tipo' => 'url', 'requerido' => true],
                    'clave' => ['label' => 'Clave para firmar (opcional)', 'tipo' => 'texto'],
                ],
            ],
        ];
    }

    /** Las acciones que ofrece esta instalación (las de módulos apagados no). */
    public static function accionesDisponibles(): array
    {
        return array_filter(self::acciones(), fn ($a) => $a['modulo'] === null || Modulos::activo($a['modulo']));
    }

    public static function operadores(): array
    {
        return [
            'igual' => 'es igual a', 'distinto' => 'es distinto de', 'mayor' => 'es mayor que', 'mayor_igual' => 'es mayor o igual que',
            'menor' => 'es menor que', 'menor_igual' => 'es menor o igual que', 'contiene' => 'contiene', 'no_contiene' => 'no contiene',
            'en_lista' => 'es alguno de (separados por coma)', 'vacio' => 'está vacío', 'no_vacio' => 'tiene algo',
        ];
    }

    public static function unidadesDeEspera(): array
    {
        return ['minutos' => 1, 'horas' => 60, 'dias' => 1440];
    }

    // ─── El contexto de un documento (atajos) ────────────────────────────────

    public static function contextoDe(Model $m, array $extra = []): ?array
    {
        return EntidadesAutomatizacion::contexto($m, $extra);
    }

    /** El mismo contexto, leído otra vez de la base: al salir de una espera los datos pudieron cambiar. */
    public static function refrescar(?string $clave, ?int $id): ?array
    {
        $m = EntidadesAutomatizacion::buscar($clave, $id);

        return $m ? EntidadesAutomatizacion::contexto($m) : null;
    }
}
