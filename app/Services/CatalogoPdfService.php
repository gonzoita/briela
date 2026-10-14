<?php

namespace App\Services;

use App\Models\Ensamble;
use App\Models\Producto;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;

/**
 * El catálogo completo en PDF, **siempre sin precios**.
 *
 * Es el que se manda por correo, se imprime para una feria o se descarga del sitio web: circula
 * sin control, y un precio impreso queda viejo el día que cambia la lista. Por eso este documento
 * no tiene la opción de mostrar precios; quien necesite uno, pide una cotización.
 *
 * Dos puertas, el mismo documento: el botón de la lista de productos (todo lo que se vende) y la
 * API del sitio web (solo lo publicado, lo mismo que muestra la tienda).
 */
class CatalogoPdfService
{
    /** @return Collection<int, Producto> */
    public function productos(bool $soloPublicados): Collection
    {
        return Producto::with([
                'categoria:id,nombre',
                'imagenes',
                'variantes' => fn ($q) => $q->where('activo', true)->orderBy('valor_variante')->orderBy('id'),
            ])
            ->where('activo', true)
            ->where('es_vendible', true)
            ->whereNull('producto_padre_id')
            ->when($soloPublicados, fn ($q) => $q->where('publicado_web', true))
            ->orderBy('nombre')->orderBy('id')
            ->get();
    }

    /** Los ensambles que se muestran en la web: los que se fabrican a medida. */
    public function ensambles(): Collection
    {
        return Ensamble::with('categoria:id,nombre')
            ->where('publicado_web', true)
            ->orderBy('nombre')->orderBy('id')
            ->get();
    }

    /**
     * Las fichas del catálogo agrupadas por categoría. Ninguna lleva precio: no hay de dónde
     * sacarlo aunque alguien edite la plantilla.
     *
     * @return Collection<string, Collection<int, array<string, mixed>>>
     */
    public function fichas(bool $soloPublicados): Collection
    {
        return $this->productos($soloPublicados)
            ->map(fn (Producto $p) => [
                'categoria'       => $p->categoria?->nombre ?? 'Otros',
                'nombre'          => $p->nombre,
                'referencia'      => $p->referencia,
                'marca'           => $p->marca,
                'origen'          => $p->origen,
                'descripcion'     => $p->descripcion_corta,
                'caracteristicas' => $p->caracteristicas ?? [],
                'atributo'        => $p->atributo_variante,
                'variantes'       => $p->variantes->map(fn ($v) => ['valor' => $v->valor_variante, 'referencia' => $v->referencia])->all(),
                'imagen'          => $this->imagenDe($p),
            ])
            ->concat($this->ensambles()->map(fn (Ensamble $e) => [
                'categoria'       => $e->categoria?->nombre ?? 'Fabricados a medida',
                'nombre'          => $e->nombre,
                'referencia'      => 'ENS-' . $e->id,
                'marca'           => null,
                'origen'          => null,
                'descripcion'     => $e->descripcion_corta,
                'caracteristicas' => [],
                'atributo'        => null,
                'variantes'       => [],
                'imagen'          => $this->ruta($e->imagen_principal),
            ]))
            ->groupBy('categoria')
            ->sortKeys();
    }

    public function pdf(bool $soloPublicados): \Barryvdh\DomPDF\PDF
    {
        $grupos = $this->fichas($soloPublicados);

        return Pdf::loadView('pdf.catalogo-completo', [
            'grupos' => $grupos,
            'total'  => $grupos->flatten(1)->count(),
            'fecha'  => now()->format('d/m/Y'),
        ])->setPaper('a4', 'portrait');
    }

    private function imagenDe(Producto $p): ?string
    {
        $img = $p->imagenes->firstWhere('es_principal', true) ?? $p->imagenes->first();

        return $this->ruta($img?->ruta);
    }

    /** dompdf lee la imagen del disco; si no está, la ficha sale sin foto en vez de romperse. */
    private function ruta(?string $ruta): ?string
    {
        if (! $ruta) {
            return null;
        }

        $completa = public_path('storage/' . $ruta);

        return is_file($completa) ? $completa : null;
    }
}
