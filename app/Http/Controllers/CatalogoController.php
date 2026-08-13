<?php

namespace App\Http\Controllers;

use App\Models\Ensamble;
use App\Models\Producto;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Inertia\Inertia;
use App\Support\Marca;

class CatalogoController extends Controller
{
    public function producto(int $id, Request $request)
    {
        $producto     = Producto::with(['categoria', 'imagenes'])->findOrFail($id);
        $mostrarPrecio = $request->get('precio', '1') !== '0';

        $imagenes = $producto->imagenes->map(fn ($img) => [
            'url'       => asset('storage/' . $img->ruta),
            'principal' => $img->es_principal,
        ]);

        return Inertia::render('Catalogo/Producto', [
            'producto' => array_merge($producto->only([
                'id', 'nombre', 'referencia', 'tipo', 'descripcion_corta', 'descripcion_larga',
                'unidad_medida', 'precio_cliente_final',
            ]), [
                'categoria_nombre' => $producto->categoria?->nombre,
                'imagenes'         => $imagenes->values(),
                // Cuál es el precio público lo dice la marca en Segmentación, no el nombre
                // de una columna. La clave vieja se sigue mandando mientras la pantalla se
                // cambie; esta es la que manda.
                'precio_publico'   => $this->precioPublico($producto),
            ]),
            'mostrarPrecio' => $mostrarPrecio,
        ]);
    }

    /**
     * El precio que se le muestra a quien no ha entrado al sistema.
     *
     * Antes era `precio_cliente_final`, el nombre de una columna. Si la empresa marca otro
     * canal como precio público, el catálogo tiene que seguir esa decisión — y si no hay
     * ninguno marcado, es mejor no mostrar precio que mostrar uno cualquiera: podría ser el
     * precio mayorista, que no se le enseña a un desconocido.
     */
    private function precioPublico(\Illuminate\Database\Eloquent\Model $item): ?float
    {
        // Con respaldo a la columna vieja: los ensambles guardados antes de que hubiera
        // canales configurables no tienen fila en `canal_precios`, y su ficha pública
        // salía sin precio aunque el precio estuviera guardado.
        return app(\App\Services\PreciosPorCanalService::class)->precioPublicoDe($item);
    }

    public function ensamble(int $id, Request $request)
    {
        $ensamble      = Ensamble::with(['plantilla', 'categoria'])->findOrFail($id);
        $mostrarPrecio = $request->get('precio', '1') !== '0';

        $todasImagenes = collect();
        if ($ensamble->imagen_principal) {
            $todasImagenes->push([
                'url'       => asset('storage/' . $ensamble->imagen_principal),
                'principal' => true,
            ]);
        }
        $secundarias = is_string($ensamble->imagenes_secundarias)
            ? json_decode($ensamble->imagenes_secundarias, true)
            : ($ensamble->imagenes_secundarias ?? []);
        foreach (($secundarias ?? []) as $ruta) {
            $todasImagenes->push([
                'url'       => asset('storage/' . $ruta),
                'principal' => false,
            ]);
        }

        return Inertia::render('Catalogo/Ensamble', [
            'ensamble' => array_merge($ensamble->only([
                'id', 'nombre', 'descripcion_corta', 'descripcion_larga', 'precio_cliente_final',
            ]), [
                'plantilla_nombre' => $ensamble->plantilla?->nombre,
                'categoria_nombre' => $ensamble->categoria?->nombre,
                'imagenes'         => $todasImagenes->values(),
                'precio_publico'   => $this->precioPublico($ensamble),
            ]),
            'mostrarPrecio' => $mostrarPrecio,
        ]);
    }

    public function productoPdf(int $id, Request $request)
    {
        $producto      = Producto::with(['categoria', 'imagenes'])->findOrFail($id);
        $mostrarPrecio = $request->get('precio', '1') !== '0';

        $principal  = $producto->imagenes->firstWhere('es_principal', true) ?? $producto->imagenes->first();
        $imagenPath = $principal ? public_path('storage/' . $principal->ruta) : null;

        $imagenesSecundarias = $producto->imagenes
            ->filter(fn ($img) => ! $img->es_principal)
            ->map(fn ($img) => public_path('storage/' . $img->ruta))
            ->filter(fn ($path) => file_exists($path))
            ->values()
            ->all();

        $logoPath = Marca::logoPath();

        $pdf = Pdf::loadView('pdf.catalogo-producto', [
            'producto'            => $producto,
            'imagenPath'          => $imagenPath,
            'imagenesSecundarias' => $imagenesSecundarias,
            'logoPath'            => file_exists($logoPath) ? $logoPath : null,
            'mostrarPrecio'       => $mostrarPrecio,
        ])->setPaper('a4', 'portrait');

        return $pdf->download("catalogo-{$producto->referencia}.pdf");
    }

    public function ensamblePdf(int $id, Request $request)
    {
        $ensamble      = Ensamble::with(['plantilla', 'categoria'])->findOrFail($id);
        $mostrarPrecio = $request->get('precio', '1') !== '0';

        $imagenPath = $ensamble->imagen_principal
            ? public_path('storage/' . $ensamble->imagen_principal)
            : null;

        $secundarias = is_string($ensamble->imagenes_secundarias)
            ? json_decode($ensamble->imagenes_secundarias, true)
            : ($ensamble->imagenes_secundarias ?? []);

        $imagenesSecundarias = collect($secundarias ?? [])
            ->map(fn ($ruta) => public_path('storage/' . $ruta))
            ->filter(fn ($path) => file_exists($path))
            ->values()
            ->all();

        $logoPath = Marca::logoPath();

        $pdf = Pdf::loadView('pdf.catalogo-ensamble', [
            'ensamble'            => $ensamble,
            'imagenPath'          => $imagenPath,
            'imagenesSecundarias' => $imagenesSecundarias,
            'logoPath'            => file_exists($logoPath) ? $logoPath : null,
            'mostrarPrecio'       => $mostrarPrecio,
        ])->setPaper('a4', 'portrait');

        return $pdf->download("catalogo-{$ensamble->id}.pdf");
    }
}
