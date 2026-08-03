<?php

namespace App\Http\Controllers;

use App\Models\ImagenProducto;
use App\Services\GoogleDriveService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ImagenProductoController extends Controller
{
    public function destroy(int $id): RedirectResponse
    {
        $imagen = ImagenProducto::findOrFail($id);

        if ($imagen->drive_id) {
            GoogleDriveService::delete($imagen->drive_id);
        } else {
            Storage::disk('public')->delete($imagen->ruta);
        }
        $imagen->delete();

        return back();
    }

    public function setPrincipal(int $id): RedirectResponse
    {
        $imagen = ImagenProducto::findOrFail($id);

        ImagenProducto::where('producto_id', $imagen->producto_id)->update(['es_principal' => false]);
        $imagen->update(['es_principal' => true]);

        return back();
    }
}
