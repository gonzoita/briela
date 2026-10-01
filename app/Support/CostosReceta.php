<?php

namespace App\Support;

use App\Models\Ensamble;
use App\Models\Producto;
use App\Services\FormulaEvaluatorService;

/**
 * Los costos de una receta, y quién puede recibirlos.
 *
 * Una receta calculada —la de una plantilla, la de un ensamble directo, el snapshot de un ítem—
 * trae por cada componente su precio de costo unitario y sus subtotales. Quien no tiene
 * `costos.ver` no debe recibirlos: **ni verlos ni que viajen**. Esconderlos en la pantalla y
 * mandarlos igual los deja a un F12 de distancia, en la respuesta JSON.
 *
 * Por eso las respuestas que devuelven recetas pasan por `paraQuienPregunta()`, y lo que se
 * quita es siempre la misma lista: si un día la receta gana otra columna de costo, se agrega
 * aquí y queda cerrada en todas partes a la vez.
 *
 * La receta sin precios vuelve al servidor cuando ese mismo usuario guarda la cotización o la
 * OP. `completar()` le devuelve los precios ahí, del lado del servidor, para que el snapshot
 * guardado sea igual al que habría guardado alguien con permiso.
 */
class CostosReceta
{
    /** Las columnas de costo de cada componente de una receta. */
    public const CLAVES = ['precio_unit', 'subtotal', 'subtotal_real'];

    public static function puedeVer(): bool
    {
        return (bool) auth()->user()?->tienePermiso('costos.ver');
    }

    /**
     * La receta sin sus columnas de costo. Cantidades, unidades y nombres se quedan: son lo que
     * necesita quien cotiza o fabrica.
     *
     * @param  array<int, array<string, mixed>>  $componentes
     * @return array<int, array<string, mixed>>
     */
    public static function ocultar(array $componentes): array
    {
        return array_map(
            fn ($c) => is_array($c) ? array_diff_key($c, array_flip(self::CLAVES)) : $c,
            $componentes
        );
    }

    /**
     * La receta tal como la puede recibir el usuario de esta petición.
     *
     * @param  array<int, array<string, mixed>>|null  $componentes
     * @return array<int, array<string, mixed>>|null
     */
    public static function paraQuienPregunta(?array $componentes): ?array
    {
        if ($componentes === null || self::puedeVer()) {
            return $componentes;
        }

        return self::ocultar($componentes);
    }

    /** Un monto de costo, o nada si quien pregunta no lo puede ver. */
    public static function montoParaQuienPregunta(float|int|string|null $monto): ?float
    {
        return self::puedeVer() && $monto !== null ? (float) $monto : null;
    }

    /**
     * Devuelve los precios a un snapshot que llegó sin ellos.
     *
     * Pasa cuando guarda alguien sin `costos.ver`: su pantalla recibió la receta sin precios y
     * así la manda de vuelta. Los precios se buscan en el servidor, en la misma receta de la
     * que salió el snapshot:
     *
     * - con medidas propias y plantilla, el cálculo de la plantilla con esas medidas —lo mismo
     *   que devolvió el cálculo de la cotización—;
     * - si no, la receta guardada del ensamble —lo mismo que devolvió el buscador—.
     *
     * Solo toca los componentes que no traen `precio_unit`: un snapshot completo se queda como
     * está, así que guardar dos veces no cambia nada.
     *
     * @param  array<int, array<string, mixed>>|null  $snapshot
     * @return array<int, array<string, mixed>>|null
     */
    public static function completar(?array $snapshot, int|string|null $ensambleId, ?array $variablesInstancia): ?array
    {
        if (! $snapshot || ! $ensambleId) {
            return $snapshot;
        }

        $faltan = collect($snapshot)->contains(fn ($c) => is_array($c) && ! array_key_exists('precio_unit', $c));

        if (! $faltan || ! $ensamble = Ensamble::find((int) $ensambleId)) {
            return $snapshot;
        }

        $referencia = self::recetaDeReferencia($ensamble, (array) ($variablesInstancia ?? []));
        $porNombre  = collect($referencia)->keyBy(fn ($c) => $c['nombre'] ?? '');

        $precioProducto = Producto::whereIn('id', collect($snapshot)->pluck('producto_id')->filter()->unique())
            ->pluck('precio_costo', 'id');

        foreach ($snapshot as $i => $c) {
            if (! is_array($c) || array_key_exists('precio_unit', $c)) {
                continue;
            }

            $nombre = $c['nombre'] ?? '';

            // Primero la misma posición —la receta sale del mismo cálculo y en el mismo orden—,
            // después el nombre, y si no aparece, el costo del producto hoy.
            $par = (($referencia[$i]['nombre'] ?? null) === $nombre ? $referencia[$i] : null)
                ?? $porNombre->get($nombre);

            $precio = (float) ($par['precio_unit']
                ?? $precioProducto->get($c['producto_id'] ?? null)
                ?? 0);

            $cantidad     = (float) ($c['cantidad'] ?? 0);
            $cantidadReal = (float) ($c['cantidad_real'] ?? $cantidad);

            $snapshot[$i]['precio_unit']   = $precio;
            $snapshot[$i]['subtotal']      = round($cantidad * $precio, 2);
            $snapshot[$i]['subtotal_real'] = round($cantidadReal * $precio, 2);
        }

        return $snapshot;
    }

    /** @return array<int, array<string, mixed>> */
    private static function recetaDeReferencia(Ensamble $ensamble, array $variablesInstancia): array
    {
        if ($ensamble->plantilla_id && $variablesInstancia !== []) {
            try {
                return app(FormulaEvaluatorService::class)->calcularPlantilla(
                    $ensamble->plantilla_id,
                    array_merge((array) ($ensamble->variables ?? []), $variablesInstancia)
                );
            } catch (\Throwable $e) {
                \Log::error('CostosReceta::completar — no se pudo recalcular la receta', [
                    'ensamble_id' => $ensamble->id,
                    'error'       => $e->getMessage(),
                ]);
            }
        }

        return array_values((array) ($ensamble->componentes_resultado ?? []));
    }
}
