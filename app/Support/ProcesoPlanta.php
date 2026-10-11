<?php

namespace App\Support;

use App\Models\TemplateTrabajo;
use Illuminate\Database\Eloquent\Model;

/**
 * Guardar el flujo de planta de algo que se fabrica o se procesa: sus pasos y su revisión.
 *
 * Lo usan la ficha del ensamble y la del producto. Es la misma lista con las mismas reglas —un
 * solo paso final, borrar y reescribir solo si cambió—, así que vive en un solo sitio: escrita
 * dos veces, el producto terminaría con dos pasos finales el día que se arregle solo uno.
 */
class ProcesoPlanta
{
    /**
     * Reemplaza los pasos del flujo, si la lista dice algo distinto de lo guardado.
     *
     * `sincronizarPasos()` borra y recrea las filas, y cada recreación deja en null el
     * `template_paso_id` de los trabajos en curso: no pierden sus pasos —cada uno guarda su
     * copia— pero sí el hilo con la plantilla. Por eso solo se escribe cuando algo cambió.
     *
     * @param  array<int, array<string, mixed>>  $pasos
     */
    public static function guardarPasos(TemplateTrabajo $template, array $pasos): void
    {
        $pasos = array_values(array_filter($pasos, fn ($p) => trim((string) ($p['nombre'] ?? '')) !== ''));

        if ($pasos === []) {
            return;
        }

        $pasos = self::normalizarPasos($pasos);

        if (self::mismosPasos($template, $pasos)) {
            return;
        }

        $template->sincronizarPasos($pasos);
    }

    /**
     * Ordena la lista y deja un único paso final.
     *
     * El paso final es el que entrega la unidad: con dos marcados la entrega se haría dos
     * veces, y con ninguno no se haría nunca.
     *
     * @param  array<int, array<string, mixed>>  $pasos
     * @return array<int, array<string, mixed>>
     */
    public static function normalizarPasos(array $pasos): array
    {
        $final = null;

        foreach ($pasos as $i => $paso) {
            if (filter_var($paso['es_paso_final'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                $final = $i;
            }
        }

        $final ??= count($pasos) - 1;

        foreach ($pasos as $i => $paso) {
            $pasos[$i]['orden']           = $i;
            $pasos[$i]['peso_porcentaje'] = (float) ($paso['peso_porcentaje'] ?? 0);
            $pasos[$i]['es_paso_final']   = $i === $final;
        }

        return $pasos;
    }

    /**
     * Si la lista que llega dice lo mismo que la guardada.
     *
     * @param  array<int, array<string, mixed>>  $pasos
     */
    public static function mismosPasos(TemplateTrabajo $template, array $pasos): bool
    {
        $guardados = $template->pasos()->orderBy('orden')->get();

        if ($guardados->count() !== count($pasos)) {
            return false;
        }

        foreach ($guardados as $i => $g) {
            $p = $pasos[$i];

            $igual = $g->nombre === ($p['nombre'] ?? null)
                && (string) $g->descripcion === (string) ($p['descripcion'] ?? '')
                && (string) $g->objetivo === (string) ($p['objetivo'] ?? '')
                && abs((float) $g->peso_porcentaje - (float) ($p['peso_porcentaje'] ?? 0)) < 0.01
                && (bool) $g->es_paso_final === (bool) ($p['es_paso_final'] ?? false)
                && (int) $g->nivel_dificultad === (int) ($p['nivel_dificultad'] ?? 1)
                && $g->bodega_destino_id == ($p['bodega_destino_id'] ?? null)
                && (string) $g->imagen === (string) ($p['imagen'] ?? '')
                && (string) $g->archivo_plano === (string) ($p['archivo_plano'] ?? '');

            if (! $igual) {
                return false;
            }
        }

        return true;
    }

    /**
     * Guarda la lista de revisión de calidad de su dueño (plantilla, ensamble o producto).
     *
     * Borra y reescribe, como los pasos. Lo ya revisado no se toca: cada unidad guarda su copia
     * del punto —título, descripción y si exigía foto— justo para que cambiar la lista no
     * reescriba un historial de calidad.
     *
     * @param  array<int, array<string, mixed>>  $checks
     */
    public static function guardarChecks(Model $dueno, array $checks): void
    {
        $checks = array_values(array_filter($checks, fn ($c) => trim((string) ($c['titulo'] ?? '')) !== ''));

        $dueno->checksCalidad()->delete();

        foreach ($checks as $i => $check) {
            $dueno->checksCalidad()->create([
                'titulo'      => $check['titulo'],
                'descripcion' => $check['descripcion'] ?? null,
                'orden'       => $i,
                'exige_foto'  => filter_var($check['exige_foto'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'es_critico'  => filter_var($check['es_critico'] ?? true, FILTER_VALIDATE_BOOLEAN),
                'activo'      => true,
            ]);
        }
    }
}
