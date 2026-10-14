<?php

namespace App\Http\Controllers;

use App\Models\Archivo;
use App\Models\Producto;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Los planos y las fichas técnicas de un producto.
 *
 * Viajan con el producto a la tienda en línea y el vendedor los descarga para mandarlos. Se guardan
 * en el disco público, igual que las fotos, porque la tienda los enlaza directo.
 *
 * **El nombre del archivo guardado no sale del archivo.** `store()` le pone la extensión que
 * adivina por el contenido, y un archivo disfrazado podía terminar como `.php` dentro de una
 * carpeta pública. Aquí el nombre es aleatorio y la extensión es una de la lista cerrada.
 */
class ProductoDocumentoController extends Controller
{
    /** Planos de CAD incluidos: en planta se comparten así. */
    private const EXTENSIONES = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'dwg', 'dxf'];

    public function store(Request $request, int $id): RedirectResponse
    {
        $producto = Producto::findOrFail($id);

        $request->validate([
            'archivo'   => 'required|file|max:20480|extensions:' . implode(',', self::EXTENSIONES),
            'categoria' => 'required|in:' . implode(',', Producto::CATEGORIAS_DOCUMENTO),
        ], [
            'archivo.extensions' => 'Solo PDF, imágenes (JPG, PNG, WEBP) o planos de CAD (DWG, DXF).',
            'archivo.max'        => 'El archivo pesa más de 20 MB.',
        ]);

        $archivo   = $request->file('archivo');
        $extension = strtolower($archivo->getClientOriginalExtension());
        $ruta      = $archivo->storeAs("productos/{$producto->id}/documentos", Str::random(40) . '.' . $extension, 'public');

        $producto->documentos()->create([
            'nombre_original' => $archivo->getClientOriginalName(),
            'nombre_archivo'  => basename($ruta),
            'ruta'            => $ruta,
            'tipo_mime'       => $archivo->getMimeType(),
            'extension'       => $extension,
            'tamano'          => $archivo->getSize(),
            'categoria'       => $request->input('categoria'),
            'subido_por'      => auth()->id(),
        ]);

        return back()->with('success', $request->input('categoria') === 'plano' ? 'Plano agregado.' : 'Ficha técnica agregada.');
    }

    public function destroy(Archivo $archivo): RedirectResponse
    {
        // Solo un plano o una ficha de un producto: esta ruta no borra otros archivos del sistema.
        abort_unless($archivo->archivable instanceof Producto && in_array($archivo->categoria, Producto::CATEGORIAS_DOCUMENTO, true), 404);

        Storage::disk('public')->delete($archivo->ruta);
        $archivo->delete();

        return back()->with('success', 'Documento eliminado.');
    }
}
