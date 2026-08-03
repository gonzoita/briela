<?php

namespace App\Http\Controllers;

use App\Models\OpCuota;
use Inertia\Inertia;

class CarteraController extends Controller
{
    public function index()
    {
        // La cartera hereda la sede de la OP a la que pertenece la cuota.
        $sedeActiva = \App\Support\ContextoSede::id();

        $cuotas = OpCuota::with('op.cliente')
            ->when($sedeActiva, fn ($q) => $q->whereHas('op', fn ($q2) => $q2->where('sede_id', $sedeActiva)))
            ->whereIn('estado', ['pendiente', 'parcial'])
            ->orderBy('fecha_vencimiento')
            ->get()
            ->map(fn ($c) => [
                'id'               => $c->id,
                'op_id'            => $c->op_id,
                'op_numero'        => $c->op->numero ?? "OP-{$c->op_id}",
                'cliente'          => $c->op->cliente?->nombre ?? '—',
                'concepto'         => $c->concepto,
                'valor'            => (float) $c->valor,
                'valor_pagado'     => (float) $c->valor_pagado,
                'saldo'            => $c->saldo,
                'fecha_vencimiento'=> $c->fecha_vencimiento?->format('Y-m-d'),
                'estado'           => $c->estado,
                'semaforo'         => $c->semaforo,
            ]);

        return Inertia::render('Financiero/Cartera', [
            'cuotas' => $cuotas,
        ]);
    }
}
