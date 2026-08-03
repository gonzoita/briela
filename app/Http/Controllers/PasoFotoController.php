<?php

namespace App\Http\Controllers;

use App\Models\OpItemTrabajoPaso;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class PasoFotoController extends Controller
{
    public function store(Request $request, OpItemTrabajoPaso $paso)
    {
        $request->validate([
            'fotos'   => 'required|array',
            'fotos.*' => 'required|image|mimes:jpg,jpeg,png,webp|max:5120',
        ]);

        $fotos = $paso->fotos ?? [];

        foreach ($request->file('fotos') as $file) {
            $path  = $file->store("pasos/{$paso->id}", 'public');
            $fotos[] = $path;
        }

        $paso->update(['fotos' => $fotos]);

        return response()->json([
            'fotos' => array_map(fn ($f) => Storage::url($f), $fotos),
        ]);
    }

    public function destroy(Request $request, OpItemTrabajoPaso $paso)
    {
        $request->validate(['path' => 'required|string']);

        $fotos    = $paso->fotos ?? [];
        $relative = ltrim(str_replace('/storage/', '', $request->path), '/');

        if (in_array($relative, $fotos)) {
            Storage::disk('public')->delete($relative);
            $fotos = array_values(array_filter($fotos, fn ($f) => $f !== $relative));
            $paso->update(['fotos' => $fotos]);
        }

        return response()->json([
            'fotos' => array_map(fn ($f) => Storage::url($f), $fotos),
        ]);
    }
}
