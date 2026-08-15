<?php

namespace App\Services;

use App\Models\Ensamble;
use App\Models\OpItem;
use App\Models\OpItemTrabajo;
use App\Models\OpItemTrabajoPaso;

/**
 * Fusión Plantillas de Ensamble <-> Plantillas de Trabajo.
 *
 * Antes: al crear un OpItem con ensamble, alguien tenía que entrar a
 * producción y elegir a mano un "template de trabajo" de una lista sin
 * filtrar para recién ahí generar los pasos que ve el operario.
 *
 * Ahora: cada plantilla de ensamble tiene su propio flujo de trabajo
 * emparejado 1 a 1 (ver PlantillaEnsamble::obtenerOCrearTemplateTrabajo).
 * Este servicio genera los OpItemTrabajo (uno por unidad de cantidad) y sus
 * pasos automáticamente en el momento en que se crea el OpItem, sin ninguna
 * acción manual. Si la plantilla todavía no tiene pasos cargados, el
 * trabajo se crea igual, vacío, para que quede disponible.
 */
class TrabajoAutoGeneratorService
{
    public function generarParaItem(OpItem $item): void
    {
        if (! $item->ensamble_id) {
            return;
        }

        // Seguridad: no duplicar si el ítem ya tiene trabajos generados.
        if (OpItemTrabajo::where('op_item_id', $item->id)->exists()) {
            return;
        }

        $ensamble = Ensamble::with('plantilla')->find($item->ensamble_id);

        if (! $ensamble) {
            return;
        }

        // Un ensamble directo no tiene plantilla: su flujo de producción cuelga de él mismo.
        // Antes esto se devolvía sin hacer nada cuando faltaba la plantilla, y con los
        // ensambles directos eso dejaría la OP con cero trabajos: sin QR para el operario,
        // sin avance, y quieta en `confirmada` sin nada que explique por qué.
        $template = $ensamble->esDirecto()
            ? $ensamble->obtenerOCrearTemplateTrabajo()
            : $ensamble->plantilla?->obtenerOCrearTemplateTrabajo();

        if (! $template) {
            return;
        }

        $pasos = $template->pasos()->orderBy('orden')->get();

        $variables = $item->variables_instancia ?? [];
        $cantidad = (int) max(1, floor((float) $item->cantidad));

        for ($unidad = 1; $unidad <= $cantidad; $unidad++) {
            $trabajo = OpItemTrabajo::create([
                'op_item_id'        => $item->id,
                'template_id'       => $template->id,
                'porcentaje_avance' => 0,
                'numero_unidad'     => $unidad,
                'total_unidades'    => $cantidad,
            ]);

            foreach ($pasos as $paso) {
                $desc = preg_replace_callback('/\{(\w+)\}/', function ($m) use ($variables) {
                    return $variables[$m[1]] ?? $m[0];
                }, $paso->descripcion ?? '');

                OpItemTrabajoPaso::create([
                    'op_item_trabajo_id'   => $trabajo->id,
                    'template_paso_id'     => $paso->id,
                    'nombre'               => $paso->nombre,
                    'descripcion_resuelta' => $desc,
                    'peso_porcentaje'      => $paso->peso_porcentaje,
                    'orden'                => $paso->orden,
                    'es_paso_final'        => $paso->es_paso_final,
                    // La bodega de entrega baja de la plantilla y queda ajustable en esta OP.
                    'bodega_destino_id'    => $paso->bodega_destino_id,
                ]);
            }
        }

        // Igual que hacía OpTrabajoController::iniciar() manualmente: asigna
        // número de serie a la primera unidad si el ítem todavía no tiene.
        // OJO: a propósito NO tocamos estado_item acá (queda 'pendiente').
        // El trabajo ya existe y tiene sus pasos, pero el ítem no se marca
        // "en_proceso" hasta que alguien empiece a completar pasos de verdad
        // — así no se pisa el flujo de verificación de la OP a nivel ítem.
        if (! $item->numero_serie) {
            $serie = now()->format('Ymd') . str_pad($item->id, 4, '0', STR_PAD_LEFT) . '001';
            $item->update(['numero_serie' => $serie]);
        }
    }
}
