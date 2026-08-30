<?php

namespace App\Services;

use App\Models\Bodega;
use App\Models\OpItemTrabajo;
use App\Models\OpItemTrabajoPaso;
use Illuminate\Validation\ValidationException;

/**
 * Cerrar y reabrir un paso de trabajo. **Un solo sitio.**
 *
 * Había cuatro pantallas que cerraban un paso —el código QR del operario, el panel de la orden,
 * la hoja del trabajo y el tablero— y las cuatro lo hacían distinto. De ahí salían tres
 * problemas que parecían no tener relación:
 *
 * - **La entrega a bodega ocurría en momentos distintos.** Dos pantallas entregaban cuando no
 *   quedaba ningún paso pendiente; la tercera, al cerrar el marcado como final. Con una
 *   plantilla sin paso final, dos entregaban y una nunca.
 * - **Los puntos solo se otorgaban por el código QR**, pero se devolvían desde la hoja del
 *   trabajo. Se perdían por un camino que nunca los daba.
 * - **La bodega se preguntaba distinto** en cada una.
 *
 * Ahora las cuatro pasan por aquí, así que la regla es una sola y no se pueden volver a
 * separar. Lo que decide la regla:
 *
 * 1. Un paso se cierra con su hora, sus operarios y sus puntos.
 * 2. **La unidad entra a bodega al cerrarse el paso final**, y solo si no queda ningún otro
 *    paso pendiente. Un paso final cerrado antes que los demás no entrega nada: la unidad no
 *    está armada, y meterla al inventario diría que sí.
 * 3. Las dos bodegas —a dónde entra lo fabricado, de dónde salió el material— se guardan en la
 *    unidad. Vienen de la orden y se pueden corregir al cerrar.
 */
class CierrePasoService
{
    public function __construct(
        private EntregaAlmacenService $almacen,
        private PuntosColaboradorService $puntos,
    ) {}

    /**
     * Cierra un paso. Devuelve la bodega a la que entró la unidad, o null si no entró.
     *
     * `$operarios` es la lista de quienes lo hicieron: `[['operario_id' => 1, 'tiempo_minutos'
     * => 30, 'observaciones' => '…'], …]`. Puede venir vacía —el tablero cierra de un toque—
     * y entonces no hay a quién darle puntos, que es lo honesto.
     *
     * @throws ValidationException cuando el paso final no puede cerrarse todavía.
     */
    public function cerrar(
        OpItemTrabajoPaso $paso,
        array $operarios = [],
        ?int $bodegaEntregaId = null,
        ?int $bodegaMaterialId = null,
    ): ?Bodega {
        $trabajo = $paso->trabajo;

        if ($paso->es_paso_final) {
            $this->exigirQueSeaElUltimo($paso, $trabajo);
            $this->guardarBodegas($trabajo, $bodegaEntregaId, $bodegaMaterialId);
        }

        $yaEstaba = (bool) $paso->completado;

        $paso->update([
            'completado'     => true,
            'completado_at'  => $paso->completado_at ?? now(),
            // Un paso que se cierra sin haberse iniciado deja la línea de tiempo incompleta.
            'iniciado_at'    => $paso->iniciado_at ?? $paso->completado_at ?? now(),
            'operario_id'    => $operarios[0]['operario_id']    ?? $paso->operario_id,
            'tiempo_minutos' => $operarios[0]['tiempo_minutos'] ?? $paso->tiempo_minutos,
        ]);

        if ($operarios !== []) {
            $paso->operarios()->delete();

            foreach ($operarios as $quien) {
                $paso->operarios()->create([
                    'operario_id'    => $quien['operario_id'],
                    'tiempo_minutos' => $quien['tiempo_minutos'] ?? $paso->duracionRealMinutos(),
                    'observaciones'  => $quien['observaciones'] ?? null,
                ]);
            }
        }

        // Los puntos van a quien quedó registrado en el paso, venga de donde venga el cierre.
        // `otorgarPuntosPorPaso` es idempotente, así que volver a cerrar un paso ya cerrado no
        // los duplica.
        foreach ($paso->operarios()->pluck('operario_id') as $operarioId) {
            $this->puntos->otorgarPuntosPorPaso($paso, $operarioId);
        }

        $trabajo->recalcularAvance();

        // La entrega va al final: necesita el avance ya recalculado y el paso ya cerrado, y
        // `entregado_at` la hace idempotente si el paso se vuelve a marcar.
        if ($paso->es_paso_final && ! $yaEstaba) {
            return $this->almacen->entregar($trabajo->fresh());
        }

        return null;
    }

    /**
     * Reabre un paso: le quita la marca, sus operarios y los puntos que otorgó.
     *
     * **No devuelve la unidad de la bodega ni repone el material.** Se gastó de verdad y la
     * unidad está armada en un estante: deshacer el movimiento diría que no existe algo que sí
     * existe. Lo que corresponde ahí es un ajuste de inventario, que es una decisión de quien
     * cuenta el estante, no un efecto secundario de corregir una marca.
     */
    public function reabrir(OpItemTrabajoPaso $paso): void
    {
        $this->puntos->revertirPuntosPorPaso($paso->id);

        $paso->operarios()->delete();
        $paso->update(['completado' => false, 'completado_at' => null]);

        $trabajo = $paso->trabajo;
        $trabajo->recalcularAvance();

        $item = $trabajo->opItem;

        if ($item && $item->estado_item === 'terminado') {
            $item->update(['estado_item' => 'en_proceso']);
        }
    }

    /**
     * Lo que la pantalla necesita para preguntar las dos bodegas del paso final.
     *
     * Vienen ya elegidas, y en este orden: lo que se eligió en la **unidad anterior de la
     * misma orden**, y si no, lo que declaró la orden al confirmarse. Lo primero importa
     * porque una orden de diez puertas cierra su paso final diez veces: sin memoria, son
     * veinte respuestas idénticas y a la tercera se contestan sin leer.
     */
    public function bodegasSugeridas(OpItemTrabajo $trabajo): array
    {
        $op = $trabajo->opItem?->op;

        $anterior = $op
            ? OpItemTrabajo::whereHas('opItem', fn ($q) => $q->where('op_id', $op->id))
                ->whereNotNull('entregado_at')
                ->where('id', '!=', $trabajo->id)
                ->orderByDesc('entregado_at')
                ->first()
            : null;

        return [
            'entrega'  => $anterior?->bodega_entrega_id  ?? $op?->bodega_entrega_id,
            'material' => $anterior?->bodega_material_id ?? $op?->bodega_material_id,
        ];
    }

    /**
     * El paso final es el que entrega, así que no se puede cerrar con trabajo por delante.
     *
     * Antes esto no se revisaba y el orden de los pasos era una sugerencia: cerrar «Embocinar»
     * primero metía al inventario una puerta que todavía no tenía marco.
     */
    private function exigirQueSeaElUltimo(OpItemTrabajoPaso $paso, OpItemTrabajo $trabajo): void
    {
        $faltan = $trabajo->pasos()
            ->where('id', '!=', $paso->id)
            ->where('completado', false)
            ->count();

        if ($faltan > 0) {
            throw ValidationException::withMessages([
                'paso' => "Este es el paso que entrega la unidad, y todavía faltan {$faltan} paso(s) "
                    . 'por cerrar. Ciérralos primero: al cerrar este, la unidad entra a bodega y '
                    . 'sus materiales se descuentan.',
            ]);
        }
    }

    /**
     * Guarda las dos bodegas en la unidad, cayendo a las sugeridas si no llegó ninguna.
     *
     * Se exige tener las dos: sin la de entrega, la unidad no sabe a dónde entrar; sin la del
     * material, el descuento se haría contra la de entrega —una bodega de producto terminado
     * que no guarda insumos— y ahí `registrarMovimiento()` lo recorta a cero en silencio. Un
     * descuento que no descuenta nada es peor que un error, porque nadie lo ve.
     */
    private function guardarBodegas(OpItemTrabajo $trabajo, ?int $entrega, ?int $material): void
    {
        $sugeridas = $this->bodegasSugeridas($trabajo);

        $entrega  = $entrega  ?: ($trabajo->bodega_entrega_id  ?: $sugeridas['entrega']);
        $material = $material ?: ($trabajo->bodega_material_id ?: $sugeridas['material']);

        // El respaldo de las órdenes viejas: nacieron sin los campos de la orden, y lo único
        // que les queda es lo que dejó la plantilla en el paso.
        $entrega ??= $trabajo->pasos()
            ->whereNotNull('bodega_destino_id')
            ->orderByDesc('es_paso_final')
            ->value('bodega_destino_id');

        $faltan = [];

        if (! $entrega)  $faltan['bodega_entrega_id']  = 'Elige a qué bodega entra la unidad terminada.';
        if (! $material) $faltan['bodega_material_id'] = 'Elige de qué bodega salieron los insumos de esta unidad.';

        if ($faltan !== []) {
            throw ValidationException::withMessages($faltan);
        }

        $trabajo->update([
            'bodega_entrega_id'  => $entrega,
            'bodega_material_id' => $material,
        ]);
    }
}
