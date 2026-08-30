<?php

namespace App\Services;

use App\Models\Ensamble;
use App\Models\OpItem;
use App\Models\OpItemTrabajo;
use App\Models\OpItemTrabajoCheck;
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
    /**
     * Qué le pasaría a las unidades de un ítem si cambiara su cantidad o su ensamble.
     *
     * Se pregunta **antes** de guardar. Crear o borrar unidades no es un detalle del guardado:
     * cada unidad es una puerta con su código QR, sus pasos y su revisión de calidad, y
     * borrarla en silencio se lleva por delante el trabajo que alguien ya registró.
     *
     * Devuelve `['crear' => n, 'borrar' => n, 'con_avance' => n, 'mensaje' => '…']`, o null si
     * no hay nada que cambiar.
     */
    public function cambiosPorCantidad(OpItem $item, int $nuevaCantidad, ?int $nuevoEnsambleId = null): ?array
    {
        $trabajos = $item->trabajos()->get();

        if ($trabajos->isEmpty()) {
            return null;
        }

        $nuevaCantidad  = max(1, $nuevaCantidad);
        $cambioEnsamble = $nuevoEnsambleId !== null && (int) $nuevoEnsambleId !== (int) $item->ensamble_id;

        // Un cambio de ensamble invalida TODOS los pasos: son los de la receta vieja.
        if ($cambioEnsamble) {
            $conAvance = $trabajos->filter(fn ($t) => $this->tieneAvance($t))->count();

            return [
                'crear'      => $nuevaCantidad,
                'borrar'     => $trabajos->count(),
                'con_avance' => $conAvance,
                'mensaje'    => $conAvance > 0
                    ? "Este ítem cambia de ensamble, y {$conAvance} de sus unidades ya tienen trabajo registrado. "
                        . 'Cambiar el ensamble las dejaría con los pasos de la receta anterior, así que no se puede: '
                        . 'crea un ítem nuevo, o reabre esos pasos primero.'
                    : "Este ítem cambia de ensamble: sus {$trabajos->count()} unidad(es) se rehacen con los pasos "
                        . 'del ensamble nuevo. Ninguna tiene trabajo registrado, así que no se pierde nada.',
                'bloqueado'  => $conAvance > 0,
            ];
        }

        $actual = $trabajos->count();

        if ($actual === $nuevaCantidad) {
            return null;
        }

        if ($nuevaCantidad > $actual) {
            $faltan = $nuevaCantidad - $actual;

            return [
                'crear'      => $faltan,
                'borrar'     => 0,
                'con_avance' => 0,
                'mensaje'    => "Se crearán {$faltan} unidad(es) más para este ítem, con sus pasos, su código QR "
                    . 'y su revisión de calidad.',
                'bloqueado'  => false,
            ];
        }

        // Baja la cantidad: se borran las últimas, y solo las que nadie tocó.
        $sobran      = $actual - $nuevaCantidad;
        $borrables   = $trabajos->sortByDesc('numero_unidad')
            ->filter(fn ($t) => ! $this->tieneAvance($t))
            ->take($sobran);
        $intocables  = $sobran - $borrables->count();

        return [
            'crear'      => 0,
            'borrar'     => $borrables->count(),
            'con_avance' => $intocables,
            'mensaje'    => $intocables > 0
                ? "No se puede bajar a {$nuevaCantidad}: hay {$intocables} unidad(es) de más que ya tienen trabajo "
                    . 'registrado o entraron a bodega. Reabre sus pasos primero, o deja la cantidad en '
                    . ($actual - $borrables->count()) . '.'
                : "Se eliminarán {$sobran} unidad(es) de este ítem. Ninguna tiene trabajo registrado.",
            'bloqueado'  => $intocables > 0,
        ];
    }

    /**
     * Deja las unidades del ítem igual a su cantidad: crea las que faltan, borra las que
     * sobran, y pone al día el «de cuántas» de todas.
     *
     * No borra nunca una unidad con avance: eso lo decide `cambiosPorCantidad()` antes, y la
     * pantalla ya lo preguntó. Aquí se vuelve a comprobar de todas formas — el candado va en
     * el servidor, no en la pantalla.
     */
    public function sincronizarParaItem(OpItem $item, bool $rehacerTodo = false): void
    {
        if (! $item->ensamble_id) {
            return;
        }

        if ($rehacerTodo) {
            foreach ($item->trabajos()->get() as $trabajo) {
                if (! $this->tieneAvance($trabajo)) {
                    $this->borrarUnidad($trabajo);
                }
            }
        }

        $cantidad = (int) max(1, floor((float) $item->cantidad));
        $trabajos = $item->trabajos()->orderBy('numero_unidad')->get();

        // Sobran: se van las últimas sin avance.
        if ($trabajos->count() > $cantidad) {
            $sobran = $trabajos->count() - $cantidad;

            foreach ($trabajos->sortByDesc('numero_unidad') as $trabajo) {
                if ($sobran <= 0) {
                    break;
                }

                if (! $this->tieneAvance($trabajo)) {
                    $this->borrarUnidad($trabajo);
                    $sobran--;
                }
            }
        }

        // Faltan: se crean con el mismo molde que las primeras.
        $existentes = $item->trabajos()->count();

        if ($existentes < $cantidad) {
            $this->crearUnidades($item, $existentes + 1, $cantidad);
        }

        // El «unidad 2 de 5» de todas tiene que decir la verdad después de mover la cantidad.
        $total = $item->trabajos()->count();

        $item->trabajos()->update(['total_unidades' => $total]);

        // Y la numeración se renumera de 1 a N: al borrar la 2 de 3, quedaban una «1 de 2» y
        // una «3 de 2», que además de mentir se ve como un error del sistema.
        foreach ($item->trabajos()->orderBy('numero_unidad')->get() as $i => $trabajo) {
            if ($trabajo->numero_unidad !== $i + 1) {
                $trabajo->update(['numero_unidad' => $i + 1]);
            }
        }
    }

    /** Una unidad que alguien ya tocó: un paso cerrado, entregada a bodega, o remisionada. */
    private function tieneAvance(OpItemTrabajo $trabajo): bool
    {
        return $trabajo->entregado_at
            || $trabajo->remisionado
            || $trabajo->pasos()->where('completado', true)->exists()
            || $trabajo->pasos()->whereNotNull('iniciado_at')->exists()
            || $trabajo->checks()->where('resultado', '!=', 'pendiente')->exists();
    }

    private function borrarUnidad(OpItemTrabajo $trabajo): void
    {
        foreach ($trabajo->pasos()->get() as $paso) {
            $paso->operarios()->delete();
            $paso->delete();
        }

        $trabajo->checks()->delete();
        $trabajo->delete();
    }

    public function generarParaItem(OpItem $item): void
    {
        if (! $item->ensamble_id) {
            return;
        }

        // Seguridad: no duplicar si el ítem ya tiene trabajos generados.
        if (OpItemTrabajo::where('op_item_id', $item->id)->exists()) {
            return;
        }

        $this->crearUnidades($item, 1, (int) max(1, floor((float) $item->cantidad)));

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

    /**
     * Crea las unidades `$desde..$hasta` de un ítem, cada una con sus pasos y su revisión.
     *
     * Vive aparte porque se usa dos veces: al nacer el ítem y al subirle la cantidad después.
     * Escrito dos veces, la unidad que se agregara mañana nacería distinta a las de ayer.
     */
    private function crearUnidades(OpItem $item, int $desde, int $hasta): void
    {
        $ensamble = Ensamble::with('plantilla')->find($item->ensamble_id);

        if (! $ensamble) {
            return;
        }

        // Un ensamble directo no tiene plantilla: su flujo de producción cuelga de él mismo.
        // Sin esto, una OP con un ensamble directo nacía con cero trabajos: sin QR para el
        // operario, sin avance, y quieta en `confirmada` sin nada que lo explique.
        $template = $ensamble->esDirecto()
            ? $ensamble->obtenerOCrearTemplateTrabajo()
            : $ensamble->plantilla?->obtenerOCrearTemplateTrabajo();

        if (! $template) {
            return;
        }

        $pasos = $template->pasos()->orderBy('orden')->get();

        // La lista de revisión de calidad vive donde viven los pasos: en el ensamble si es
        // directo, en la plantilla si no. Se copia a cada unidad y se congela ahí — cambiar la
        // plantilla después no puede reescribir lo que alguien ya revisó.
        $checks = ($ensamble->esDirecto() ? $ensamble : $ensamble->plantilla)
            ?->checksCalidad()->where('activo', true)->get() ?? collect();

        $variables = $item->variables_instancia ?? [];

        for ($unidad = $desde; $unidad <= $hasta; $unidad++) {
            $trabajo = OpItemTrabajo::create([
                'op_item_id'        => $item->id,
                'template_id'       => $template->id,
                'porcentaje_avance' => 0,
                'numero_unidad'     => $unidad,
                'total_unidades'    => $hasta,
            ]);

            foreach ($checks as $check) {
                OpItemTrabajoCheck::create([
                    'op_item_trabajo_id'   => $trabajo->id,
                    'checklist_calidad_id' => $check->id,
                    'titulo'               => $check->titulo,
                    'descripcion'          => $check->descripcion,
                    'orden'                => $check->orden,
                    'exige_foto'           => $check->exige_foto,
                    'es_critico'           => $check->es_critico,
                ]);
            }

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
                    'depende_de'           => $paso->depende_de,
                    'es_paso_final'        => $paso->es_paso_final,
                    // La bodega de entrega baja de la plantilla y queda ajustable en esta OP.
                    'bodega_destino_id'    => $paso->bodega_destino_id,
                ]);
            }
        }
    }
}
