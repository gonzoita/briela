<?php

namespace App\Http\Controllers;

use App\Models\Archivo;
use App\Models\Op;
use App\Services\ArchivoServidorService;
use App\Services\GoogleDriveService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ArchivoController extends Controller
{
    public function index(Request $request)
    {
        $query = Archivo::with('subidoPor')->orderBy('created_at', 'desc');

        if ($request->filled('categoria')) {
            $query->where('categoria', $request->categoria);
        }
        if ($request->filled('buscar')) {
            $query->where('nombre_original', 'like', '%' . $request->buscar . '%');
        }

        $archivos = $query->paginate(24)->withQueryString();

        $archivosData = $archivos->through(fn ($a) => [
            'id'           => $a->id,
            'nombre'       => $a->nombre_original,
            'url'          => $a->url,
            'es_imagen'    => $a->es_imagen,
            'tamano'       => $a->tamano_formateado,
            'categoria'    => $a->categoria,
            'extension'    => $a->extension,
            'descripcion'  => $a->descripcion,
            'subido_por'   => $a->subidoPor->name ?? '—',
            'created_at'   => $a->created_at->format('d/m/Y H:i'),
        ]);

        return Inertia::render('Multimedia/Index', [
            'archivos' => $archivosData,
            'filtros'  => $request->only(['categoria', 'buscar']),
            'stats'    => [
                'total'        => Archivo::count(),
                'imagenes'     => Archivo::whereIn('extension', ['jpg', 'jpeg', 'png', 'webp'])->count(),
                'pdfs'         => Archivo::where('extension', 'pdf')->count(),
                'tamano_total' => Archivo::sum('tamano'),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'archivo'     => 'required|file|max:20480',
            'categoria'   => 'required|in:plano,foto_calidad,documento,otro',
            'descripcion' => 'nullable|string|max:200',
            'op_id'       => 'nullable|exists:ops,id',
        ]);

        $file    = $request->file('archivo');
        $ext     = strtolower($file->getClientOriginalExtension());
        $nombre  = \Illuminate\Support\Str::uuid() . '.' . $ext;
        // Los archivos van al propio servidor, no a Google Drive. Drive
        // entregaba enlaces de vista previa (páginas web) que el navegador no
        // podía mostrar en un <img>, y ataba algo tan básico como subir una
        // foto a credenciales de un servicio externo.
        $resultado   = ArchivoServidorService::subir($file, 'multimedia/' . $request->categoria, $nombre);
        $ruta        = $resultado['url'];
        $driveId     = null;
        $driveUrl    = null;
        $storageType = 'local';

        $datos = [
            'nombre_original' => $file->getClientOriginalName(),
            'nombre_archivo'  => $driveId ?? $nombre,
            'ruta'            => $ruta,
            'drive_id'        => $driveId,
            'drive_url'       => $driveUrl,
            'storage'         => $storageType,
            'tipo_mime'       => $file->getMimeType(),
            'extension'       => $ext,
            'tamano'          => $file->getSize(),
            'categoria'       => $request->categoria,
            'subido_por'      => auth()->id(),
            'descripcion'     => $request->descripcion,
        ];

        if ($request->filled('op_id')) {
            $op = Op::findOrFail($request->op_id);
            $op->archivos()->create($datos);
        } else {
            $datos['archivable_type'] = 'App\Models\User';
            $datos['archivable_id']   = auth()->id();
            Archivo::create($datos);
        }

        return back()->with('success', 'Archivo subido correctamente.');
    }

    public function destroy(Archivo $archivo)
    {
        if (!empty($archivo->drive_id)) {
            GoogleDriveService::delete($archivo->drive_id);
        } else {
            ArchivoServidorService::borrar($archivo->ruta);
        }
        $archivo->delete();

        return back()->with('success', 'Archivo eliminado.');
    }

    public function porOp(Op $op)
    {
        return response()->json(
            $op->archivos()->with('subidoPor')->get()->map(fn ($a) => [
                'id'         => $a->id,
                'nombre'     => $a->nombre_original,
                'url'        => $a->url,
                'es_imagen'  => $a->es_imagen,
                'tamano'     => $a->tamano_formateado,
                'categoria'  => $a->categoria,
                'extension'  => $a->extension,
                'subido_por' => $a->subidoPor->name ?? '—',
                'created_at' => $a->created_at->format('d/m/Y H:i'),
            ])
        );
    }
}
