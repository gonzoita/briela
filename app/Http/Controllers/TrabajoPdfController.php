<?php

namespace App\Http\Controllers;

use App\Models\Op;
use App\Models\OpItem;
use App\Models\OpItemTrabajo;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response as HttpResponse;
use SimpleSoftwareIO\QrCode\Facades\QrCode;
use App\Support\Marca;

class TrabajoPdfController extends Controller
{
    public function descargar(OpItemTrabajo $trabajo): HttpResponse
    {
        $trabajo->load([
            'opItem.op.cliente',
            'opItem.ensamble.plantilla.campos',
            'opItem.componentes.producto',
            'template',
            'pasos' => fn ($q) => $q->orderBy('orden'),
            'pasos.operarios.operario',
        ]);

        $item = $trabajo->opItem;
        $op   = $item->op;

        $urlTrabajo = url('/trabajo/' . $trabajo->token_trabajo);
        $qrTrabajo  = 'data:image/svg+xml;base64,' . base64_encode(
            QrCode::format('svg')->size(120)->generate($urlTrabajo)
        );

        $urlOp = url('/op/' . $op->token_publico);
        $qrOp  = 'data:image/svg+xml;base64,' . base64_encode(
            QrCode::format('svg')->size(120)->generate($urlOp)
        );

        $camposPlantilla = $item->ensamble?->plantilla?->campos
            ->where('tipo_campo', 'entrada')
            ->sortBy('orden')
            ->map(fn ($c) => [
                'nombre'   => $c->nombre,
                'etiqueta' => $c->etiqueta ?? $c->nombre,
                'imagen'   => $c->imagen_referencia
                    ? storage_path('app/public/' . $c->imagen_referencia)
                    : null,
            ])->values()->toArray() ?? [];

        $pdf = Pdf::loadView('pdf.trabajo', [
            'trabajo'              => $trabajo,
            'item'                 => $item,
            'op'                   => $op,
            'qrTrabajo'            => $qrTrabajo,
            'qrOp'                 => $qrOp,
            'camposPlantilla'      => $camposPlantilla,
            'logoPath'             => Marca::logoPath(),
            'fecha'                => now()->format('d/m/Y H:i'),
            'componentesOrdenados' => $this->ordenarComponentes($item->componentes),
        ])->setPaper('a4', 'portrait');

        return $pdf->download("trabajo-{$op->numero}-U{$trabajo->numero_unidad}.pdf");
    }

    public function descargarTodos(Op $op, OpItem $item): HttpResponse
    {
        $item->load([
            'op.cliente',
            'ensamble.plantilla.campos',
            'componentes.producto',
            'trabajos.template',
            'trabajos.pasos' => fn ($q) => $q->orderBy('orden'),
            'trabajos.pasos.operarios.operario',
        ]);

        $trabajos = $item->trabajos->sortBy('numero_unidad');

        $urlOp = url('/op/' . $op->token_publico);
        $qrOp  = 'data:image/svg+xml;base64,' . base64_encode(
            QrCode::format('svg')->size(120)->generate($urlOp)
        );

        $camposPlantilla = $item->ensamble?->plantilla?->campos
            ->where('tipo_campo', 'entrada')
            ->sortBy('orden')
            ->map(fn ($c) => [
                'nombre'   => $c->nombre,
                'etiqueta' => $c->etiqueta ?? $c->nombre,
                'imagen'   => $c->imagen_referencia
                    ? storage_path('app/public/' . $c->imagen_referencia)
                    : null,
            ])->values()->toArray() ?? [];

        $pdf = Pdf::loadView('pdf.trabajos-todos', [
            'trabajos'             => $trabajos,
            'item'                 => $item,
            'op'                   => $op,
            'qrOp'                 => $qrOp,
            'camposPlantilla'      => $camposPlantilla,
            'logoPath'             => Marca::logoPath(),
            'fecha'                => now()->format('d/m/Y H:i'),
            'componentesOrdenados' => $this->ordenarComponentes($item->componentes),
        ])->setPaper('a4', 'portrait');

        $nombre = str_replace(['/', ' '], '-', $item->descripcion ?? 'items');
        return $pdf->download("trabajos-{$op->numero}-{$nombre}.pdf");
    }

    private function ordenarComponentes($componentes): array
    {
        $padres   = $componentes->filter(fn ($c) => is_null($c->parent_componente_id))->values();
        $resultado = [];

        foreach ($padres as $padre) {
            $resultado[] = ['comp' => $padre, 'es_hijo' => false];
            $hijos = $componentes->filter(fn ($c) => $c->parent_componente_id === $padre->id)->values();
            foreach ($hijos as $hijo) {
                $resultado[] = ['comp' => $hijo, 'es_hijo' => true];
            }
        }

        return $resultado;
    }
}
