<?php

namespace App\Services\IA;

use App\Models\Cotizacion;
use App\Models\CrmEtapa;
use App\Models\CrmLead;
use App\Models\MovimientoBancario;
use App\Models\Op;
use App\Models\OpCuota;
use App\Models\OpItem;
use App\Models\OpItemTrabajo;
use App\Models\OpPago;
use App\Models\Remision;
use App\Services\CierrePasoService;
use App\Services\RemisionService;
use App\Support\ContextoSede;
use Illuminate\Support\Facades\DB;

/**
 * Lo que la guía puede HACER en los procesos de la empresa, siempre con confirmación.
 *
 * Cada transacción tiene dos fases:
 *
 * 1. **Proponer** (`proponer()`): la guía la pide con lo que entendió de la conversación —«la
 *    OP-0012», «la bisagra», «150 mil por transferencia»—. Aquí se ubica cada cosa en la base,
 *    se revisa que se pueda hacer y se arma un resumen. **No se escribe nada.**
 * 2. **Ejecutar** (`ejecutar()`): solo cuando la persona toca «Confirmar» en la tarjeta. Se
 *    vuelven a revisar el permiso, la sede y el estado —entre la propuesta y el clic pudo
 *    cambiar algo— y se hace con los MISMOS servicios que usan las pantallas
 *    (`RemisionService`, `CierrePasoService`, `OpItem::alistar()`…). La guía no tiene un camino
 *    propio: si lo tuviera, una OP creada por la guía nacería distinta a una creada a mano.
 *
 * Cada transacción exige el permiso del módulo, el mismo que pide su pantalla, y un módulo
 * apagado se lleva sus permisos (`User::permisos()`): la guía no puede más que quien la usa.
 */
class TransaccionesIaService
{
    public static function catalogo(): array
    {
        return [
            'generar_op' => [
                'descripcion' => 'Genera la orden de producción de una cotización APROBADA.',
                'parametros'  => ['cotizacion' => 'número de la cotización (obligatorio)', 'fecha_entrega' => 'fecha de entrega AAAA-MM-DD (opcional)'],
                'permiso'     => 'ops.crear',
            ],
            'alistar' => [
                'descripcion' => 'Marca como alistados los productos de una orden (listos para remisionar). Sin producto, alista todos los pendientes.',
                'parametros'  => ['op' => 'número de la orden (obligatorio)', 'producto' => 'nombre del producto (opcional)'],
                'permiso'     => 'alistamiento.alistar',
            ],
            'remisionar' => [
                'descripcion' => 'Crea la remisión de una orden con todo lo que está listo para despachar. Lo remisionado sale del almacén.',
                'parametros'  => ['op' => 'número de la orden (obligatorio)'],
                'permiso'     => 'remisiones.crear',
            ],
            'mover_remision' => [
                'descripcion' => 'Cambia el estado de una remisión: confirmada, en_camino, entregada o anulada.',
                'parametros'  => ['remision' => 'número de la remisión (obligatorio)', 'estado' => 'confirmada | en_camino | entregada | anulada'],
                'permiso'     => 'remisiones.editar',
            ],
            'registrar_pago' => [
                'descripcion' => 'Registra un pago recibido de una orden (abona a la cuota pendiente más antigua).',
                'parametros'  => ['op' => 'número de la orden (obligatorio)', 'valor' => 'monto (obligatorio)', 'medio_pago' => 'efectivo | transferencia | cheque', 'referencia' => 'referencia o número de transferencia (opcional)'],
                'permiso'     => 'cartera.editar',
            ],
            'confirmar_abono' => [
                'descripcion' => 'Confirma un abono del banco que está por decidir en el verificador de pagos, aplicándolo a la cuota de una orden.',
                'parametros'  => ['valor' => 'monto del abono (obligatorio)', 'op' => 'número de la orden a la que pertenece (obligatorio)'],
                'permiso'     => 'verificador.editar',
            ],
            'cerrar_paso' => [
                'descripcion' => 'Marca como hecho un paso de planta de un ensamble (por ejemplo «Cortar»). Sin paso, termina todos los pasos pendientes de la unidad.',
                'parametros'  => ['op' => 'número de la orden (obligatorio)', 'producto' => 'nombre del ensamble (opcional si la orden tiene uno)', 'paso' => 'nombre del paso (opcional)', 'unidad' => 'número de unidad (opcional; sin ella, la primera pendiente)'],
                'permiso'     => 'trabajos.editar',
            ],
            'aprobar_calidad' => [
                'descripcion' => 'Aprueba en calidad las unidades terminadas de una orden (las deja listas para remisionar).',
                'parametros'  => ['op' => 'número de la orden (obligatorio)'],
                'permiso'     => 'ops.calidad',
            ],
            'cambiar_estado_cotizacion' => [
                'descripcion' => 'Cambia el estado de una cotización: enviada, aprobada o rechazada.',
                'parametros'  => ['cotizacion' => 'número de la cotización (obligatorio)', 'estado' => 'enviada | aprobada | rechazada', 'motivo' => 'motivo del rechazo (opcional)'],
                'permiso'     => 'cotizaciones.editar',
            ],
            'mover_lead' => [
                'descripcion' => 'Mueve un lead del CRM a otra etapa del embudo (incluidas ganado o perdido).',
                'parametros'  => ['lead' => 'título, nombre o empresa del lead (obligatorio)', 'etapa' => 'nombre de la etapa (obligatorio)', 'motivo' => 'motivo, si se pierde (opcional)'],
                'permiso'     => 'crm.editar',
            ],
            'crear_lead' => [
                'descripcion' => 'Registra un lead nuevo en el CRM.',
                'parametros'  => ['nombre' => 'nombre del contacto (obligatorio)', 'telefono' => 'teléfono (opcional)', 'email' => 'correo (opcional)', 'empresa' => 'empresa (opcional)', 'mensaje' => 'qué necesita (opcional)'],
                'permiso'     => 'crm.crear',
            ],
        ];
    }

    public function disponibles(): array
    {
        $user = auth()->user();

        return collect(static::catalogo())->filter(fn ($cfg) => $user?->tienePermiso($cfg['permiso']))->all();
    }

    /**
     * Arma la propuesta. Devuelve ['exito' => true, 'propuesta' => [accion, parametros, resumen]]
     * o ['exito' => false, 'falta' => '…'] para que la guía pregunte lo que falta.
     */
    public function proponer(string $accion, array $p): ?array
    {
        if (! array_key_exists($accion, $this->disponibles())) {
            return null;
        }

        try {
            [$parametros, $resumen] = $this->{'preparar' . str_replace('_', '', ucwords($accion, '_'))}($p);
        } catch (FaltaDato $e) {
            return ['exito' => false, 'falta' => $e->getMessage()];
        }

        return [
            'exito'     => true,
            // Lo que se pidió y lo que se resolvió: al confirmar se resuelve otra vez y se compara.
            'propuesta' => ['accion' => $accion, 'parametros' => ['entrada' => $p, 'ids' => $parametros], 'resumen' => $resumen],
            'aviso'     => 'NO se ha hecho todavía: la persona debe tocar «Confirmar» en la tarjeta.',
        ];
    }

    /**
     * Ejecuta una propuesta confirmada. Vuelve a preparar con lo que se pidió —permiso, sede y
     * estado se revisan otra vez—, exige que salga lo mismo que se propuso, y solo entonces escribe.
     *
     * @return array{exito: bool, mensaje: string, url?: ?string}
     */
    public function ejecutar(string $accion, array $p): array
    {
        if (! array_key_exists($accion, $this->disponibles())) {
            return ['exito' => false, 'mensaje' => 'No tienes permiso para hacer esto.'];
        }

        try {
            [$parametros] = $this->{'preparar' . str_replace('_', '', ucwords($accion, '_'))}($p['entrada'] ?? []);

            // Si entre la propuesta y el clic cambió lo que se iba a hacer —se alistó otro
            // producto, alguien remisionó primero—, no se hace a ciegas: se pide de nuevo.
            if (($p['ids'] ?? null) != $parametros) {
                return ['exito' => false, 'mensaje' => 'Algo cambió desde que te lo propuse. Pídemelo otra vez para revisarlo con lo que hay ahora.'];
            }

            return $this->{'hacer' . str_replace('_', '', ucwords($accion, '_'))}($parametros);
        } catch (FaltaDato $e) {
            return ['exito' => false, 'mensaje' => $e->getMessage()];
        } catch (\Illuminate\Validation\ValidationException $e) {
            return ['exito' => false, 'mensaje' => collect($e->errors())->flatten()->first() ?? 'No se pudo.'];
        } catch (\RuntimeException $e) {
            return ['exito' => false, 'mensaje' => $e->getMessage()];
        }
    }

    // ─── Generar OP ──────────────────────────────────────────────────────────

    private function prepararGenerarOp(array $p): array
    {
        $cot = $this->cotizacion($p);

        if ($cot->en_produccion) {
            throw new FaltaDato("La cotización {$cot->numero} ya tiene su orden de producción.");
        }
        if ($cot->estado !== 'aprobada') {
            throw new FaltaDato("La cotización {$cot->numero} está «{$cot->estado}»: solo se genera la orden de una cotización aprobada.");
        }

        $fecha = ! empty($p['fecha_entrega']) && strtotime((string) $p['fecha_entrega'])
            ? date('Y-m-d', strtotime((string) $p['fecha_entrega']))
            : $cot->fechaEntregaSugerida()?->toDateString();

        return [
            ['cotizacion_id' => $cot->id, 'fecha_entrega' => $fecha],
            "Generar la orden de producción de la cotización {$cot->numero} ({$cot->cliente?->nombreCompleto()}, "
                . $cot->items()->count() . ' ítem(s))' . ($fecha ? ", con entrega el {$fecha}" : '') . '.',
        ];
    }

    private function hacerGenerarOp(array $p): array
    {
        $cot = Cotizacion::findOrFail($p['cotizacion_id']);
        $op  = app(\App\Http\Controllers\OpController::class)->generarOp($cot, ['fecha_entrega_estimada' => $p['fecha_entrega']]);

        return ['exito' => true, 'mensaje' => "Listo: orden {$op->numero} generada desde la cotización {$cot->numero}.", 'url' => "/produccion/ops/{$op->id}"];
    }

    // ─── Alistar ─────────────────────────────────────────────────────────────

    private function prepararAlistar(array $p): array
    {
        $op    = $this->op($p);
        $items = $op->items()->whereNull('ensamble_id')->whereNotIn('tipo', Op::TIPOS_SIN_DESPACHO)
            ->where('estado_item', '!=', 'terminado')->doesntHave('trabajos')->get();

        if (! empty($p['producto'])) {
            $items = $items->filter(fn ($i) => str_contains(mb_strtolower($i->descripcion), mb_strtolower(trim((string) $p['producto']))));
        }

        if ($items->isEmpty()) {
            throw new FaltaDato("La orden {$op->numero} no tiene productos pendientes de alistar" . (! empty($p['producto']) ? " que coincidan con «{$p['producto']}»" : '') . '.');
        }

        return [
            ['op_id' => $op->id, 'items' => $items->pluck('id')->values()->all()],
            "Alistar en la orden {$op->numero}: " . $items->map(fn ($i) => "{$i->descripcion} ×" . (float) $i->cantidad)->implode(', ') . '.',
        ];
    }

    private function hacerAlistar(array $p): array
    {
        $hechos = [];
        foreach (OpItem::whereIn('id', $p['items'])->where('op_id', $p['op_id'])->get() as $item) {
            if ($motivo = $item->alistar(true)) {
                return ['exito' => false, 'mensaje' => $motivo];
            }
            $hechos[] = $item->descripcion;
        }
        $op = Op::find($p['op_id']);

        return ['exito' => true, 'mensaje' => 'Alistado: ' . implode(', ', $hechos) . ". La orden {$op->numero} quedó «{$op->fresh()->estadoBadge()['label']}».", 'url' => "/produccion/ops/{$op->id}"];
    }

    // ─── Remisionar ──────────────────────────────────────────────────────────

    private function prepararRemisionar(array $p): array
    {
        $op    = $this->op($p);
        $lista = $op->items()->whereNotIn('tipo', Op::TIPOS_SIN_DESPACHO)->get()
            ->map(fn ($i) => ['item' => $i, 'unidades' => $i->cantidadDisponible()])
            ->filter(fn ($l) => $l['unidades'] > 0)->values();

        if ($lista->isEmpty()) {
            throw new FaltaDato("La orden {$op->numero} no tiene nada listo para remisionar: los ensambles se remisionan cuando terminan en planta y pasan calidad.");
        }

        return [
            ['op_id' => $op->id, 'items' => $lista->map(fn ($l) => ['op_item_id' => $l['item']->id, 'unidades' => $l['unidades']])->all()],
            "Remisionar de la orden {$op->numero}: " . $lista->map(fn ($l) => "{$l['item']->descripcion} ×{$l['unidades']}")->implode(', ')
                . '. Lo remisionado sale del almacén al crearla.',
        ];
    }

    private function hacerRemisionar(array $p): array
    {
        $items = collect($p['items'])->map(function ($l) {
            $item = OpItem::findOrFail($l['op_item_id']);

            return ['op_item_id' => $item->id, 'descripcion' => $item->descripcion, 'cantidad' => $l['unidades'], 'cantidad_unidades' => $l['unidades']];
        })->all();

        $servicio = app(RemisionService::class);

        if ($errores = $servicio->errores($p['op_id'], $items)) {
            return ['exito' => false, 'mensaje' => reset($errores)];
        }

        $remision = $servicio->crear(['tipo' => 'op', 'op_id' => $p['op_id'], 'items' => $items, 'notas' => 'Creada desde la guía.']);

        return ['exito' => true, 'mensaje' => "Listo: remisión {$remision->numero} creada; lo que lleva ya salió del almacén.", 'url' => "/logistica/remisiones/{$remision->id}"];
    }

    // ─── Mover remisión ──────────────────────────────────────────────────────

    private function prepararMoverRemision(array $p): array
    {
        $texto = trim((string) ($p['remision'] ?? ''));
        if ($texto === '') {
            throw new FaltaDato('¿Qué remisión? Dime su número.');
        }

        $remision = $this->porNumero(Remision::query(), $texto, 'remisión');
        $estado   = strtolower(str_replace([' ', '-'], '_', trim((string) ($p['estado'] ?? ''))));

        if (! in_array($estado, ['confirmada', 'en_camino', 'entregada', 'anulada'], true)) {
            throw new FaltaDato('¿A qué estado la paso? Puede ser confirmada, en camino, entregada o anulada.');
        }
        if (! $remision->puedePasarA($estado)) {
            throw new FaltaDato("La remisión {$remision->numero} está «{$remision->estadoBadge()['label']}» y no puede pasar a «{$estado}».");
        }

        return [
            ['remision_id' => $remision->id, 'estado' => $estado],
            "Pasar la remisión {$remision->numero} a «" . str_replace('_', ' ', $estado) . '»'
                . ($estado === 'anulada' ? ': vuelve al almacén todo lo que sacó.' : '.'),
        ];
    }

    private function hacerMoverRemision(array $p): array
    {
        $remision = Remision::findOrFail($p['remision_id']);

        if ($motivo = app(RemisionService::class)->cambiarEstado($remision, $p['estado'])) {
            return ['exito' => false, 'mensaje' => $motivo];
        }

        return ['exito' => true, 'mensaje' => "Listo: la remisión {$remision->numero} quedó «{$remision->fresh()->estadoBadge()['label']}».", 'url' => "/logistica/remisiones/{$remision->id}"];
    }

    // ─── Pagos ───────────────────────────────────────────────────────────────

    private function prepararRegistrarPago(array $p): array
    {
        $op    = $this->op($p);
        $valor = $this->monto($p['valor'] ?? null);
        $medio = in_array($p['medio_pago'] ?? '', ['efectivo', 'transferencia', 'cheque'], true) ? $p['medio_pago'] : 'transferencia';
        $cuota = $op->cuotas()->whereIn('estado', ['pendiente', 'parcial'])->orderBy('numero_cuota')->first();

        return [
            ['op_id' => $op->id, 'valor' => $valor, 'medio_pago' => $medio, 'referencia' => $p['referencia'] ?? null, 'cuota_id' => $cuota?->id],
            "Registrar un pago de " . \App\Support\Monedas::formatear($valor) . " por {$medio} a la orden {$op->numero}"
                . ($cuota ? " (abona a la cuota «{$cuota->concepto}»)" : ' (la orden no tiene cuotas pendientes: queda como abono suelto)') . '.',
        ];
    }

    private function hacerRegistrarPago(array $p): array
    {
        $op   = Op::findOrFail($p['op_id']);
        $pago = OpPago::registrar($op, [
            'valor' => $p['valor'], 'medio_pago' => $p['medio_pago'], 'fecha_pago' => now()->toDateString(),
            'referencia' => $p['referencia'], 'cuota_id' => $p['cuota_id'], 'notas' => 'Registrado desde la guía.',
        ]);

        return ['exito' => true, 'mensaje' => "Listo: pago {$pago->numero_recibo} registrado en la orden {$op->numero}.", 'url' => "/produccion/ops/{$op->id}"];
    }

    private function prepararConfirmarAbono(array $p): array
    {
        $op    = $this->op($p);
        $valor = $this->monto($p['valor'] ?? null);

        $abonos = MovimientoBancario::whereIn('estado', ['pendiente', 'dudoso'])->whereBetween('valor', [$valor - 0.5, $valor + 0.5])->get();
        if ($abonos->isEmpty()) {
            throw new FaltaDato('No hay ningún abono por decidir en el verificador por ' . \App\Support\Monedas::formatear($valor) . '.');
        }
        if ($abonos->count() > 1) {
            throw new FaltaDato('Hay ' . $abonos->count() . ' abonos por decidir con ese valor. Confírmalo desde el verificador de pagos, donde se ven sus fechas y descripciones.');
        }

        $cuota = $op->cuotas()->whereIn('estado', ['pendiente', 'parcial'])->orderBy('numero_cuota')->first();
        if (! $cuota) {
            throw new FaltaDato("La orden {$op->numero} no tiene cuotas pendientes a las que aplicar el abono.");
        }

        $abono = $abonos->first();

        return [
            ['movimiento_id' => $abono->id, 'cuota_id' => $cuota->id],
            'Confirmar el abono del banco de ' . \App\Support\Monedas::formatear($valor) . " del {$abono->fecha?->format('d/m/Y')} como pago de la cuota «{$cuota->concepto}» de la orden {$op->numero}.",
        ];
    }

    private function hacerConfirmarAbono(array $p): array
    {
        $mov   = MovimientoBancario::findOrFail($p['movimiento_id']);
        $cuota = OpCuota::findOrFail($p['cuota_id']);
        $pago  = app(\App\Services\VerificadorPagosService::class)->aplicar($mov, $cuota, auth()->id());

        return ['exito' => true, 'mensaje' => "Listo: abono confirmado como pago {$pago->numero_recibo}.", 'url' => '/financiero/verificador-pagos'];
    }

    // ─── Planta y calidad ────────────────────────────────────────────────────

    private function prepararCerrarPaso(array $p): array
    {
        $op      = $this->op($p);
        $items   = $op->items()->whereNotNull('ensamble_id')->whereHas('trabajos')->get();
        $buscado = mb_strtolower(trim((string) ($p['producto'] ?? '')));

        if ($buscado !== '') {
            $items = $items->filter(fn ($i) => str_contains(mb_strtolower($i->descripcion), $buscado));
        }
        if ($items->isEmpty()) {
            throw new FaltaDato("La orden {$op->numero} no tiene ensambles en planta" . ($buscado !== '' ? " que coincidan con «{$p['producto']}»" : '') . '.');
        }
        if ($items->count() > 1) {
            throw new FaltaDato('¿De cuál ensamble? ' . $items->pluck('descripcion')->implode(' · '));
        }

        $item     = $items->first();
        $unidades = $item->trabajos()->where('porcentaje_avance', '<', 100)->orderBy('numero_unidad');
        $trabajo  = ! empty($p['unidad'])
            ? $item->trabajos()->where('numero_unidad', (int) $p['unidad'])->first()
            : $unidades->first();

        if (! $trabajo) {
            throw new FaltaDato("«{$item->descripcion}» no tiene unidades pendientes" . (! empty($p['unidad']) ? " con el número {$p['unidad']}" : '') . '.');
        }

        $pendientes = $trabajo->pasos()->where('completado', false)->orderBy('orden')->get();
        if (! empty($p['paso'])) {
            $pendientes = $pendientes->filter(fn ($x) => str_contains(mb_strtolower($x->nombre), mb_strtolower(trim((string) $p['paso']))))->take(1);
        }
        if ($pendientes->isEmpty()) {
            throw new FaltaDato("La unidad {$trabajo->numero_unidad} de «{$item->descripcion}» no tiene pendiente " . (! empty($p['paso']) ? "un paso «{$p['paso']}»" : 'ningún paso') . '.');
        }

        return [
            ['trabajo_id' => $trabajo->id, 'pasos' => $pendientes->pluck('id')->values()->all()],
            'Marcar como hecho en la unidad ' . $trabajo->numero_unidad . " de «{$item->descripcion}» (orden {$op->numero}): "
                . $pendientes->pluck('nombre')->implode(', ') . '.',
        ];
    }

    private function hacerCerrarPaso(array $p): array
    {
        $trabajo = OpItemTrabajo::findOrFail($p['trabajo_id']);
        $cierre  = app(CierrePasoService::class);

        // En orden: el paso final exige que los demás estén cerrados.
        foreach ($trabajo->pasos()->whereIn('id', $p['pasos'])->where('completado', false)->orderBy('es_paso_final')->orderBy('orden')->get() as $paso) {
            $cierre->cerrar($paso);
        }

        $trabajo->refresh();

        return ['exito' => true, 'mensaje' => "Listo: la unidad {$trabajo->numero_unidad} va en {$trabajo->porcentaje_avance} %.", 'url' => "/trabajos/{$trabajo->id}"];
    }

    private function prepararAprobarCalidad(array $p): array
    {
        $op       = $this->op($p);
        $unidades = OpItemTrabajo::whereHas('opItem', fn ($q) => $q->where('op_id', $op->id))
            ->where('porcentaje_avance', 100)->whereNull('calidad_revisada_at')->get();

        $fallas = $unidades->filter(fn ($u) => $u->checks()->where('resultado', 'falla')->where('es_critico', true)->exists());
        $foto   = $unidades->filter(fn ($u) => $u->checks()->where('resultado', 'pendiente')->where('exige_foto', true)->exists());
        $listas = $unidades->diff($fallas)->diff($foto);

        if ($listas->isEmpty()) {
            throw new FaltaDato("La orden {$op->numero} no tiene unidades terminadas para aprobar"
                . ($fallas->isNotEmpty() ? ' (' . $fallas->count() . ' con falla crítica)' : '')
                . ($foto->isNotEmpty() ? ' (' . $foto->count() . ' exigen foto: se aprueban desde Calidad)' : '') . '.');
        }

        return [
            ['op_id' => $op->id, 'unidades' => $listas->pluck('id')->values()->all()],
            "Aprobar en calidad {$listas->count()} unidad(es) de la orden {$op->numero}: quedan listas para remisionar.",
        ];
    }

    private function hacerAprobarCalidad(array $p): array
    {
        foreach (OpItemTrabajo::whereIn('id', $p['unidades'])->get() as $unidad) {
            $unidad->checks()->where('resultado', 'pendiente')->update(['resultado' => 'cumple', 'revisado_por' => auth()->id(), 'revisado_at' => now()]);
            $unidad->firmarCalidad();
        }

        $op = Op::findOrFail($p['op_id']);
        $op->sincronizarEstado();

        return ['exito' => true, 'mensaje' => count($p['unidades']) . " unidad(es) aprobadas. La orden {$op->numero} quedó «{$op->fresh()->estadoBadge()['label']}».", 'url' => "/produccion/ops/{$op->id}"];
    }

    // ─── Cotizaciones y CRM ──────────────────────────────────────────────────

    private function prepararCambiarEstadoCotizacion(array $p): array
    {
        $cot    = $this->cotizacion($p);
        $estado = strtolower(trim((string) ($p['estado'] ?? '')));

        if (! in_array($estado, ['enviada', 'aprobada', 'rechazada'], true)) {
            throw new FaltaDato('¿A qué estado la paso? Enviada, aprobada o rechazada.');
        }
        if ($cot->en_produccion) {
            throw new FaltaDato("La cotización {$cot->numero} ya está en producción y no cambia de estado.");
        }

        return [
            ['cotizacion_id' => $cot->id, 'estado' => $estado, 'motivo' => $p['motivo'] ?? null],
            "Pasar la cotización {$cot->numero} ({$cot->cliente?->nombreCompleto()}) a «{$estado}».",
        ];
    }

    private function hacerCambiarEstadoCotizacion(array $p): array
    {
        $cot = Cotizacion::findOrFail($p['cotizacion_id']);
        $cot->update(array_filter([
            'estado'         => $p['estado'],
            'motivo_rechazo' => $p['estado'] === 'rechazada' ? ($p['motivo'] ?? null) : null,
        ], fn ($v) => $v !== null));

        return ['exito' => true, 'mensaje' => "Listo: la cotización {$cot->numero} quedó «{$p['estado']}».", 'url' => "/cotizaciones/{$cot->id}"];
    }

    private function prepararMoverLead(array $p): array
    {
        $texto = trim((string) ($p['lead'] ?? ''));
        if ($texto === '') {
            throw new FaltaDato('¿Qué lead? Dime su nombre, empresa o título.');
        }

        $leads = ContextoSede::aplicar(CrmLead::query())->where('estado', 'activo')
            ->where(fn ($q) => $q->where('titulo', 'like', "%{$texto}%")->orWhere('nombre_contacto', 'like', "%{$texto}%")->orWhere('empresa_contacto', 'like', "%{$texto}%"))
            ->limit(5)->get();

        if ($leads->isEmpty()) {
            throw new FaltaDato("No encontré un lead abierto que coincida con «{$texto}».");
        }
        if ($leads->count() > 1) {
            throw new FaltaDato('Hay varios leads que coinciden: ' . $leads->pluck('titulo')->implode(' · ') . '. ¿Cuál?');
        }

        $etapa = CrmEtapa::where('activa', true)->where('nombre', 'like', '%' . trim((string) ($p['etapa'] ?? '')) . '%')->first();
        if (! $etapa || trim((string) ($p['etapa'] ?? '')) === '') {
            throw new FaltaDato('¿A qué etapa? Las del embudo son: ' . CrmEtapa::where('activa', true)->orderBy('orden')->pluck('nombre')->implode(', ') . '.');
        }

        $lead = $leads->first();

        return [
            ['lead_id' => $lead->id, 'etapa_id' => $etapa->id, 'motivo' => $p['motivo'] ?? null],
            "Mover el lead «{$lead->titulo}» a la etapa «{$etapa->nombre}»" . ($etapa->es_ganado ? ' (ganado)' : ($etapa->es_perdido ? ' (perdido)' : '')) . '.',
        ];
    }

    private function hacerMoverLead(array $p): array
    {
        $lead  = CrmLead::findOrFail($p['lead_id']);
        $orden = (int) CrmLead::where('etapa_id', $p['etapa_id'])->max('orden_en_etapa') + 1;

        // El mismo movimiento que el tablero: cierre, actividad y cotización automática.
        app(\App\Http\Controllers\CrmPipelineController::class)->moverLead(
            \Illuminate\Http\Request::create('/', 'POST', ['etapa_id' => $p['etapa_id'], 'orden_en_etapa' => $orden, 'motivo_cierre' => $p['motivo']]),
            $lead
        );

        return ['exito' => true, 'mensaje' => "Listo: «{$lead->titulo}» quedó en «{$lead->fresh()->etapa?->nombre}».", 'url' => '/crm'];
    }

    private function prepararCrearLead(array $p): array
    {
        $nombre = trim((string) ($p['nombre'] ?? ''));
        if ($nombre === '') {
            throw new FaltaDato('¿Cómo se llama el contacto?');
        }

        return [
            ['nombre' => $nombre, 'telefono' => $p['telefono'] ?? null, 'email' => $p['email'] ?? null, 'empresa' => $p['empresa'] ?? null, 'mensaje' => $p['mensaje'] ?? null],
            "Registrar en el CRM a {$nombre}" . (! empty($p['empresa']) ? " ({$p['empresa']})" : '') . (! empty($p['telefono']) ? ", tel. {$p['telefono']}" : '') . '.',
        ];
    }

    private function hacerCrearLead(array $p): array
    {
        $r = app(\App\Services\LeadEntranteService::class)->registrar([
            'canal' => 'manual', 'detalle' => 'Registrado desde la guía', 'responsable_id' => auth()->id(), 'avisar' => false,
        ] + $p);

        return ['exito' => true, 'mensaje' => $r['nuevo'] ? "Listo: {$p['nombre']} quedó en el CRM." : "{$p['nombre']} ya estaba en el CRM: le sumé este contacto.", 'url' => '/crm'];
    }

    // ─── Búsquedas ───────────────────────────────────────────────────────────

    private function op(array $p): Op
    {
        $texto = trim((string) ($p['op'] ?? ''));
        if ($texto === '') {
            throw new FaltaDato('¿De qué orden de producción? Dime su número.');
        }

        $op = $this->porNumero(ContextoSede::aplicar(Op::query()), $texto, 'orden');

        if (! $op->cliente_id) {
            throw new FaltaDato("La orden {$op->numero} no tiene cliente: asígnaselo en la pantalla de la orden antes de seguir.");
        }

        return $op;
    }

    private function cotizacion(array $p): Cotizacion
    {
        $texto = trim((string) ($p['cotizacion'] ?? ''));
        if ($texto === '') {
            throw new FaltaDato('¿Qué cotización? Dime su número.');
        }

        $cot = $this->porNumero(ContextoSede::aplicar(Cotizacion::query()), $texto, 'cotización');

        if (! $cot->cliente_id) {
            throw new FaltaDato("La cotización {$cot->numero} no tiene cliente: asígnaselo en la pantalla de la cotización antes de seguir.");
        }

        return $cot;
    }

    /** Por número exacto, o por la parte numérica («12» encuentra «OP-0012»). */
    private function porNumero($query, string $texto, string $que)
    {
        $exacto = (clone $query)->where('numero', $texto)->first();
        if ($exacto) {
            return $exacto;
        }

        $digitos = ltrim(preg_replace('/\D/', '', $texto), '0');
        if ($digitos === '') {
            throw new FaltaDato("No entendí el número de la {$que} «{$texto}».");
        }

        $candidatos = (clone $query)->where('numero', 'like', "%{$digitos}")->limit(5)->get()
            ->filter(fn ($m) => ltrim(preg_replace('/\D/', '', preg_replace('/^.*?(\d+)$/', '$1', $m->numero)), '0') === $digitos)->values();

        if ($candidatos->isEmpty()) {
            throw new FaltaDato("No encontré la {$que} «{$texto}» en esta sede.");
        }
        if ($candidatos->count() > 1) {
            throw new FaltaDato("Hay varias que coinciden con «{$texto}»: " . $candidatos->pluck('numero')->implode(', ') . '. ¿Cuál?');
        }

        return $candidatos->first();
    }

    private function monto(mixed $valor): float
    {
        // Un número llega tal cual; un texto como «150.000» o «$150.000» se lee sin puntos de miles.
        $limpio = is_numeric($valor)
            ? (float) $valor
            : (float) str_replace(',', '.', preg_replace('/[^\d,]/', '', (string) $valor));
        if ($limpio <= 0) {
            throw new FaltaDato('¿Por qué valor?');
        }

        return $limpio;
    }
}
