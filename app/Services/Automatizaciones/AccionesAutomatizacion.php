<?php

namespace App\Services\Automatizaciones;

use App\Models\AgenteInterno;
use App\Models\BandejaConversacion;
use App\Models\Comentario;
use App\Models\Cotizacion;
use App\Models\CotizacionSeguimiento;
use App\Models\CrmActividad;
use App\Models\CrmEtapa;
use App\Models\CrmLead;
use App\Models\CrmTarea;
use App\Models\Producto;
use App\Models\SolicitudCompra;
use App\Models\SolicitudCompraItem;
use App\Models\User;
use App\Models\WhatsappConversacion;
use App\Services\Agentes\LanzadorAgentes;
use App\Services\IA\IaService;
use App\Services\NotificacionService;
use App\Support\Modulos;
use Illuminate\Support\Facades\DB;

/**
 * Lo que hace cada acción de una automatización. El catálogo está en `CatalogoAutomatizaciones`.
 *
 * Todas pasan por los modelos y servicios de siempre, y todas se vuelven a preguntar **en el
 * momento de ejecutar** si el autor todavía puede hacerlo: una automatización armada hace meses por
 * alguien que hoy ya no tiene ese permiso deja de poder usarlo, en vez de seguir actuando con un
 * permiso que ya no existe.
 *
 * Lanza `\RuntimeException` con un motivo entendible cuando algo no se puede; el motor lo deja en
 * la caja negra de la corrida.
 */
class AccionesAutomatizacion
{
    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $ctx      los datos del documento y las variables de la corrida
     * @return array{resumen: string, variables: array<string, mixed>} lo que hizo (o, en simulación, lo
     *   que haría) y lo que deja para los pasos siguientes
     */
    public function ejecutar(string $accion, array $config, array $ctx, User $autor, bool $simular, string $nombre = ''): array
    {
        $def = CatalogoAutomatizaciones::acciones()[$accion] ?? null;

        if (! $def) {
            throw new \RuntimeException("La acción «{$accion}» no existe.");
        }

        if ($def['modulo'] !== null && ! Modulos::activo($def['modulo'])) {
            throw new \RuntimeException("«{$def['label']}» necesita un módulo que está apagado.");
        }

        if ($def['permiso'] && ! $autor->tienePermiso($def['permiso'])) {
            throw new \RuntimeException("Quien armó esta automatización ya no tiene el permiso {$def['permiso']}.");
        }

        $tipo = $ctx['_tipo'] ?? null;

        if ($def['sin_sensibles'] && $tipo && (EntidadesAutomatizacion::def($tipo)['sensible'] ?? false)) {
            throw new \RuntimeException("«{$def['label']}» no se usa con datos de colaboradores.");
        }

        // En la prueba con datos de ejemplo no hay un documento de verdad que leer: las acciones que
        // trabajan sobre él se describen en vez de fallar por no encontrarlo.
        if ($simular && $def['entidades'] !== null && (int) ($ctx['_id'] ?? 0) === 0) {
            return ['resumen' => "Haría: {$def['label']} (con un documento real, no con los datos de ejemplo).", 'variables' => []];
        }

        $r = match ($accion) {
            'notificar'              => $this->notificar($config, $ctx, $autor, $simular),
            'comentar_documento'     => $this->comentar($config, $ctx, $autor, $simular, $nombre),
            'crear_tarea_crm'        => $this->crearTarea($config, $ctx, $autor, $simular),
            'nota_cotizacion'        => $this->crearNota($config, $ctx, $autor, $simular),
            'mover_lead'             => $this->moverLead($config, $ctx, $simular, $nombre),
            'asignar_lead'           => $this->asignarLead($config, $ctx, $simular),
            'asignar_conversacion'   => $this->asignarConversacion($config, $ctx, $simular),
            'crear_solicitud_compra' => $this->solicitarCompra($config, $ctx, $autor, $simular, $nombre),
            'redactar_ia'            => $this->redactar($config, $ctx, $simular),
            'clasificar_ia'          => $this->clasificar($config, $ctx, $simular),
            'ejecutar_agente'        => $this->lanzarAgente($config, $ctx, $autor, $simular),
            'webhook'                => $this->webhook($config, $ctx, $simular, $nombre),
        };

        return is_string($r) ? ['resumen' => $r, 'variables' => []] : $r;
    }

    private static function verbo(bool $simular, string $condicional, string $pasado): string
    {
        return $simular ? $condicional : $pasado;
    }

    // ─── Avisos ──────────────────────────────────────────────────────────────

    /** Los ids de las personas a las que se refiere un «a quién» (avisos y aprobaciones). */
    public static function destinatarios(array $c, array $ctx, User $autor): array
    {
        $ids = match ($c['destinatario'] ?? '') {
            'responsable' => array_filter([(int) ($ctx['_responsable_id'] ?? 0)]),
            'usuarios'    => array_map('intval', (array) ($c['usuarios'] ?? [])),
            'permiso'     => User::where('activo', true)->get()
                                ->filter(fn (User $u) => $u->tienePermiso((string) ($c['permiso'] ?? '')))
                                ->pluck('id')->all(),
            'autor'       => [$autor->id],
            default       => [],
        };

        // Solo personas activas: un aviso a alguien que ya no entra no lo lee nadie.
        return $ids === [] ? [] : User::whereIn('id', array_unique($ids))->where('activo', true)->pluck('id')->all();
    }

    private function notificar(array $c, array $ctx, User $autor, bool $simular): string
    {
        $ids = self::destinatarios($c, $ctx, $autor);

        if ($ids === []) {
            // Sin a quién avisar no es un fallo de la automatización: el documento simplemente
            // no tiene responsable (una cotización suelta, por ejemplo).
            return 'No había a quién avisar.';
        }

        $titulo  = mb_substr(EvaluadorCondiciones::rellenar((string) ($c['titulo'] ?? ''), $ctx), 0, 190);
        $mensaje = mb_substr(EvaluadorCondiciones::rellenar((string) ($c['mensaje'] ?? ''), $ctx), 0, 1000);

        if (! $simular) {
            $servicio = app(NotificacionService::class);

            foreach ($ids as $id) {
                $servicio->crear($id, 'automatizacion_aviso', $titulo, $mensaje ?: null, $ctx['_url'] ?? null);
            }
        }

        return self::verbo($simular, 'Avisaría', 'Avisó') . ' a ' . count($ids) . (count($ids) === 1 ? ' persona' : ' personas') . ": «{$titulo}».";
    }

    private function comentar(array $c, array $ctx, User $autor, bool $simular, string $nombre): string
    {
        $clase = [
            'op' => \App\Models\Op::class, 'cotizacion' => Cotizacion::class, 'cliente' => \App\Models\Cliente::class,
            'orden_compra' => \App\Models\OrdenCompra::class,
        ][$ctx['_tipo'] ?? ''] ?? null;

        if (! $clase || ! $clase::whereKey($ctx['_id'] ?? 0)->exists()) {
            throw new \RuntimeException('No hay un documento en el que dejar el comentario.');
        }

        $tarea   = ($c['tipo'] ?? 'comentario') === 'tarea';
        $asignar = $tarea ? (int) ($ctx['_responsable_id'] ?? 0) ?: $autor->id : null;
        $texto   = mb_substr(EvaluadorCondiciones::rellenar((string) $c['texto'], $ctx), 0, 4500);

        if (! $simular) {
            Comentario::create([
                'comentable_type' => $clase,
                'comentable_id'   => $ctx['_id'],
                'user_id'         => $autor->id,
                'contenido'       => "🤖 «{$nombre}»: {$texto}",
                'tipo'            => $tarea ? 'tarea' : 'comentario',
                'estado'          => $tarea ? 'pendiente' : null,
                'asignado_a'      => $asignar,
                'fecha_limite'    => $tarea ? now()->addDays(max(0, min(90, (int) ($c['dias'] ?? 1))))->toDateString() : null,
            ]);

            if ($tarea && $asignar && $asignar !== $autor->id) {
                app(NotificacionService::class)->crear($asignar, 'automatizacion_aviso', "Tarea automática: «{$nombre}»", mb_strimwidth($texto, 0, 120, '…'), $ctx['_url'] ?? null);
            }
        }

        return self::verbo($simular, 'Dejaría', 'Dejó') . ($tarea ? ' una tarea' : ' un comentario') . ' en el documento.';
    }

    // ─── Comercial ───────────────────────────────────────────────────────────

    private function leadDe(array $ctx): ?CrmLead
    {
        $id = ($ctx['_tipo'] ?? null) === 'lead' ? ($ctx['_id'] ?? null) : ($ctx['_lead_id'] ?? null);

        return $id ? CrmLead::find($id) : null;
    }

    private function crearTarea(array $c, array $ctx, User $autor, bool $simular): string
    {
        $lead = $this->leadDe($ctx);

        if (! $lead) {
            throw new \RuntimeException('No hay un lead al que ponerle la tarea.');
        }

        if ($lead->estado !== 'activo') {
            return "El lead «{$lead->titulo}» ya está cerrado; no se creó la tarea.";
        }

        $titulo = mb_substr(EvaluadorCondiciones::rellenar((string) $c['titulo'], $ctx), 0, 190);
        $dias   = max(0, min(90, (int) ($c['dias'] ?? 1)));
        $tipo   = in_array($c['tipo'] ?? '', ['llamada', 'email', 'reunion', 'seguimiento', 'otro'], true) ? $c['tipo'] : 'seguimiento';

        if (! $simular) {
            CrmTarea::create([
                'lead_id'           => $lead->id,
                'responsable_id'    => $lead->responsable_id ?: $autor->id,
                'titulo'            => $titulo,
                'descripcion'       => EvaluadorCondiciones::rellenar((string) ($c['descripcion'] ?? ''), $ctx) ?: null,
                'tipo'              => $tipo,
                'fecha_vencimiento' => now()->addDays($dias)->toDateString(),
            ]);
        }

        return self::verbo($simular, 'Crearía', 'Creó') . " la tarea «{$titulo}» en el lead «{$lead->titulo}».";
    }

    private function crearNota(array $c, array $ctx, User $autor, bool $simular): string
    {
        $cot = ($ctx['_tipo'] ?? null) === 'cotizacion' ? Cotizacion::find($ctx['_id'] ?? 0) : null;

        if (! $cot) {
            throw new \RuntimeException('No hay una cotización a la que agregarle la nota.');
        }

        if (! $simular) {
            CotizacionSeguimiento::create([
                'cotizacion_id' => $cot->id,
                'user_id'       => $autor->id,
                'nota'          => mb_substr(EvaluadorCondiciones::rellenar((string) $c['texto'], $ctx), 0, 1500),
            ]);
        }

        return self::verbo($simular, 'Agregaría', 'Agregó') . " una nota a la cotización {$cot->numero}.";
    }

    private function moverLead(array $c, array $ctx, bool $simular, string $nombre): string
    {
        $lead  = $this->leadDe($ctx);
        $etapa = CrmEtapa::find((int) ($c['etapa_id'] ?? 0));

        if (! $lead) {
            throw new \RuntimeException('No hay un lead que mover.');
        }

        if (! $etapa || $etapa->es_ganado || $etapa->es_perdido) {
            throw new \RuntimeException('La etapa elegida ya no existe o cierra el lead; una automatización no gana ni pierde leads.');
        }

        if ($lead->estado !== 'activo') {
            return "El lead «{$lead->titulo}» ya está cerrado; no se movió.";
        }

        if ((int) $lead->etapa_id === $etapa->id) {
            return "El lead ya estaba en «{$etapa->nombre}».";
        }

        if (! $simular) {
            $anterior = $lead->etapa_id;
            $lead->update(['etapa_id' => $etapa->id]);
            CrmActividad::registrar($lead->id, 'etapa', "Movido a etapa: {$etapa->nombre} (automatización «{$nombre}»)", ['etapa_anterior' => $anterior, 'etapa_nueva' => $etapa->id]);
        }

        return self::verbo($simular, 'Movería', 'Movió') . " el lead «{$lead->titulo}» a «{$etapa->nombre}».";
    }

    /**
     * De varias personas, la que tiene menos trabajo abierto. Empate: la que lleva más tiempo sin
     * recibir uno (el id más bajo, para que el resultado sea siempre el mismo).
     *
     * @param  list<int>  $ids
     * @param  callable(list<int>): array<int, int>  $cargas  id => cuántos tiene abiertos
     */
    private function elegir(array $ids, callable $cargas): ?int
    {
        $ids = User::whereIn('id', $ids)->where('activo', true)->orderBy('id')->pluck('id')->all();

        if ($ids === []) {
            return null;
        }

        $carga = $cargas($ids);

        usort($ids, fn ($a, $b) => [($carga[$a] ?? 0), $a] <=> [($carga[$b] ?? 0), $b]);

        return $ids[0];
    }

    private function asignarLead(array $c, array $ctx, bool $simular): string
    {
        $lead = $this->leadDe($ctx);

        if (! $lead) {
            throw new \RuntimeException('No hay un lead que asignar.');
        }

        if ($lead->responsable_id) {
            return "El lead «{$lead->titulo}» ya tenía responsable; no se cambió.";
        }

        $elegido = $this->elegir(array_map('intval', (array) ($c['usuarios'] ?? [])), fn ($ids) => CrmLead::whereIn('responsable_id', $ids)
            ->where('estado', 'activo')->groupBy('responsable_id')->select('responsable_id', DB::raw('count(*) as n'))->pluck('n', 'responsable_id')->all());

        if (! $elegido) {
            throw new \RuntimeException('Ninguna de las personas elegidas está activa.');
        }

        $quien = User::find($elegido);

        if (! $simular) {
            $lead->update(['responsable_id' => $elegido]);
            CrmActividad::registrar($lead->id, 'asignacion', "Asignado a {$quien->name} por una automatización", ['responsable_id' => $elegido]);
            app(NotificacionService::class)->crear($elegido, 'automatizacion_aviso', "Te asignaron el lead «{$lead->titulo}»", null, "/crm/leads/{$lead->id}");
        }

        return self::verbo($simular, 'Asignaría', 'Asignó') . " el lead a {$quien->name}.";
    }

    private function asignarConversacion(array $c, array $ctx, bool $simular): string
    {
        $conv = EntidadesAutomatizacion::buscar($ctx['_entidad'] ?? null, $ctx['_id'] ?? null);

        if (! $conv instanceof WhatsappConversacion && ! $conv instanceof BandejaConversacion) {
            throw new \RuntimeException('No hay una conversación que asignar.');
        }

        if ($conv->asignado_a) {
            return 'La conversación ya tenía a alguien asignado; no se cambió.';
        }

        $elegido = $this->elegir(array_map('intval', (array) ($c['usuarios'] ?? [])), function ($ids) {
            $carga = [];

            foreach ([WhatsappConversacion::class, BandejaConversacion::class] as $clase) {
                $clase::whereIn('asignado_a', $ids)->whereNull('archivada_at')->groupBy('asignado_a')
                    ->select('asignado_a', DB::raw('count(*) as n'))->pluck('n', 'asignado_a')
                    ->each(function ($n, $id) use (&$carga) { $carga[$id] = ($carga[$id] ?? 0) + $n; });
            }

            return $carga;
        });

        if (! $elegido) {
            throw new \RuntimeException('Ninguna de las personas elegidas está activa.');
        }

        $quien = User::find($elegido);

        if (! $simular) {
            $conv->update(['asignado_a' => $elegido]);
            app(NotificacionService::class)->crear($elegido, 'automatizacion_aviso', 'Te asignaron una conversación de la bandeja', $ctx['contacto'] ?? null, '/bandeja');
        }

        return self::verbo($simular, 'Asignaría', 'Asignó') . " la conversación a {$quien->name}.";
    }

    // ─── Inventario y compras ────────────────────────────────────────────────

    private function solicitarCompra(array $c, array $ctx, User $autor, bool $simular, string $nombre): string
    {
        $p = ($ctx['_tipo'] ?? null) === 'producto' ? Producto::find($ctx['_id'] ?? 0) : null;

        if (! $p) {
            throw new \RuntimeException('No hay un producto que pedir.');
        }

        if (EntidadesAutomatizacion::solicitudAbiertaDe($p->id)) {
            return "«{$p->nombre}» ya tiene una solicitud de compra abierta; no se creó otra.";
        }

        $existencias = (float) DB::table('producto_stock')->where('producto_id', $p->id)->sum('cantidad');
        $faltante    = max(0, (float) $p->stock_minimo - $existencias);

        if ($faltante <= 0) {
            return "«{$p->nombre}» ya no está por debajo del mínimo; no hace falta pedir.";
        }

        $cantidad = round($faltante * max(1, min(10, (int) ($c['multiplicador'] ?? 1))), 3);
        $motivo   = filled($c['motivo'] ?? null) ? EvaluadorCondiciones::rellenar((string) $c['motivo'], $ctx) : "Stock bajo de {$p->nombre}";

        if (! $simular) {
            DB::transaction(function () use ($p, $autor, $cantidad, $motivo, $nombre) {
                $s = SolicitudCompra::create([
                    'estado'         => 'borrador',
                    'solicitado_por' => $autor->id,
                    'motivo'         => mb_substr($motivo, 0, 250),
                    'notas'          => "Creada por la automatización «{$nombre}».",
                ]);

                SolicitudCompraItem::create([
                    'solicitud_id' => $s->id,
                    'item_id'      => $p->id,
                    'descripcion'  => $p->nombre,
                    'cantidad'     => $cantidad,
                    'unidad'       => $p->unidad_medida ?: 'und',
                ]);
            });
        }

        return self::verbo($simular, 'Pediría', 'Pidió') . " {$cantidad} {$p->unidad_medida} de «{$p->nombre}» en una solicitud de compra (borrador).";
    }

    // ─── Inteligencia artificial ─────────────────────────────────────────────

    /** Los datos del documento en texto, para la IA: solo lo público, con sus nombres de pantalla. */
    private function resumenParaIa(array $ctx): string
    {
        $campos = CatalogoAutomatizaciones::camposDe($ctx['_tipo'] ?? null);
        $lineas = [];

        foreach (EvaluadorCondiciones::publicos($ctx) as $k => $v) {
            if (is_scalar($v) && $v !== '' && isset($campos[$k]) && empty($campos[$k]['global'])) {
                $lineas[] = "- {$campos[$k]['label']}: {$v}";
            }
        }

        $doc = EntidadesAutomatizacion::def($ctx['_tipo'] ?? '')['label'] ?? 'Sin documento';

        return "Documento: {$doc}\n" . implode("\n", $lineas);
    }

    private function ia(): IaService
    {
        $ia = app(IaService::class);

        if (! $ia->configurado()) {
            throw new \RuntimeException('La IA no está configurada en esta instalación.');
        }

        return $ia;
    }

    private function redactar(array $c, array $ctx, bool $simular): array
    {
        $pedido = EvaluadorCondiciones::rellenar((string) $c['instrucciones'], $ctx);

        if ($simular) {
            return ['resumen' => "Pediría a la IA: «{$pedido}». El texto quedaría en {{texto_ia}}.", 'variables' => ['texto_ia' => '(texto de ejemplo redactado por la IA)']];
        }

        $texto = IaService::conContexto('agentes', null, null, fn () => $this->ia()->texto(
            $this->resumenParaIa($ctx) . "\n\nTarea: {$pedido}",
            'Eres un asistente interno de una empresa. Escribe en español colombiano neutro, sin voseo, breve y concreto. '
            . 'Usa solo los datos que te dan; si falta algo, no lo inventes. Responde solo con el texto pedido.',
            500,
            true,
        ));

        $texto = trim((string) $texto);

        if ($texto === '') {
            throw new \RuntimeException('La IA no devolvió texto.');
        }

        return ['resumen' => 'La IA redactó: «' . mb_strimwidth($texto, 0, 200, '…') . '»', 'variables' => ['texto_ia' => mb_substr($texto, 0, 2000)]];
    }

    private function clasificar(array $c, array $ctx, bool $simular): array
    {
        $categorias = array_values(array_filter(array_map('trim', explode(',', (string) $c['categorias']))));
        $pregunta   = EvaluadorCondiciones::rellenar((string) $c['pregunta'], $ctx);

        if ($simular) {
            return ['resumen' => "Pediría a la IA clasificar entre: " . implode(', ', $categorias) . '.', 'variables' => ['categoria_ia' => $categorias[0] ?? 'otra']];
        }

        $respuesta = IaService::conContexto('agentes', null, null, fn () => $this->ia()->texto(
            $this->resumenParaIa($ctx) . "\n\nPregunta: {$pregunta}\nResponde SOLO con una de estas opciones, exactamente como está escrita: "
            . implode(' | ', $categorias) . ' | otra',
            'Clasificas documentos de una empresa. Respondes con una sola palabra o frase de la lista, sin explicar.',
            20,
            true,
        ));

        // La IA puede responder con adornos («Urgente.»): se busca cuál de las categorías dijo.
        $limpia  = mb_strtolower(trim((string) $respuesta, " \t\n\r.\"'"));
        $elegida = collect($categorias)->first(fn ($cat) => mb_strtolower($cat) === $limpia)
            ?? collect($categorias)->first(fn ($cat) => str_contains($limpia, mb_strtolower($cat)))
            ?? 'otra';

        return ['resumen' => "La IA clasificó como «{$elegida}».", 'variables' => ['categoria_ia' => $elegida]];
    }

    private function lanzarAgente(array $c, array $ctx, User $autor, bool $simular): string
    {
        $agente = AgenteInterno::find((int) ($c['agente_id'] ?? 0));

        if (! $agente) {
            throw new \RuntimeException('El agente ya no existe.');
        }

        if (! $agente->activo) {
            throw new \RuntimeException("El agente «{$agente->nombre}» está apagado.");
        }

        // La tarea lleva el documento: así el agente sabe sobre qué trabajar sin tener que buscarlo.
        $tarea = filled($c['tarea'] ?? null) ? EvaluadorCondiciones::rellenar((string) $c['tarea'], $ctx) : null;

        if (isset($ctx['_tipo'])) {
            $tarea = trim(($tarea ?? 'Haz tu trabajo habitual, enfocado en este documento.') . "\n\n" . $this->resumenParaIa($ctx));
        }

        if (! $simular) {
            app(LanzadorAgentes::class)->manual($agente, $autor, $tarea);
        }

        return self::verbo($simular, 'Pondría', 'Puso') . " a trabajar a «{$agente->nombre}».";
    }

    // ─── Integraciones ───────────────────────────────────────────────────────

    private function webhook(array $c, array $ctx, bool $simular, string $nombre): string
    {
        $url = (string) $c['url'];
        $host = parse_url($url, PHP_URL_HOST) ?: $url;

        if ($simular) {
            return "Enviaría los datos del documento a {$host}.";
        }

        $codigo = Webhook::enviar($url, [
            'automatizacion' => $nombre,
            'documento'      => $ctx['_tipo'] ?? null,
            'id'             => $ctx['_id'] ?? null,
            'enlace'         => isset($ctx['_url']) ? rtrim((string) config('app.url'), '/') . $ctx['_url'] : null,
            'datos'          => EvaluadorCondiciones::publicos($ctx),
            'enviado_at'     => now()->toIso8601String(),
        ], $c['clave'] ?? null);

        return "Envió los datos a {$host} (respondió {$codigo}).";
    }
}
