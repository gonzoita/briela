<?php

namespace App\Http\Controllers;

use App\Models\ComisionVendedor;
use App\Models\LiquidacionComision;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pagar varias comisiones de una sola vez.
 *
 * A un vendedor no se le paga cotización por cotización: se le paga el corte, con todo lo que
 * haya entrado en él. Antes cada comisión se liquidaba sola y no quedaba ningún documento que
 * dijera «esto fue lo que se le pagó el 15, y estas comisiones entraron»: solo un montón de
 * filas con la misma fecha.
 *
 * Una liquidación en **borrador** se arma y se deshace. Al marcarla **pagada** sus comisiones
 * quedan liquidadas y el documento se cierra: es el registro de que esa plata salió.
 */
class LiquidacionComisionController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Comisiones/Liquidaciones/Index', [
            'liquidaciones' => LiquidacionComision::with('vendedor:id,name')
                ->withCount('comisiones')
                ->orderByDesc('id')
                ->paginate(25)
                ->through(fn ($l) => [
                    'id'          => $l->id,
                    'numero'      => $l->numero,
                    'vendedor'    => $l->vendedor?->name ?? '—',
                    'total'       => $l->total,
                    'estado'      => $l->estado,
                    'fecha'       => $l->fecha?->format('d/m/Y'),
                    'comisiones'  => $l->comisiones_count,
                ]),
        ]);
    }

    /**
     * El armador: un vendedor y lo que tiene sin pagar.
     *
     * Solo aparecen las comisiones que **de verdad se pueden pagar**: sin liquidar, sin estar
     * ya dentro de otra liquidación y con valor. Una comisión en cero no se paga, y ofrecerla
     * solo ensucia la lista.
     */
    public function create(Request $request): Response
    {
        $vendedorId = $request->integer('user_id') ?: null;

        return Inertia::render('Comisiones/Liquidaciones/Create', [
            'vendedores' => User::whereHas('comisiones', fn ($q) => $this->pendientes($q))
                ->orderBy('name')
                ->get(['id', 'name']),
            'vendedor_id' => $vendedorId,
            'pendientes'  => $vendedorId
                ? $this->pendientes(ComisionVendedor::where('user_id', $vendedorId))
                    ->with('cotizacion:id,numero,fecha_creacion,cliente_id', 'cotizacion.cliente:id,nombre,apellido')
                    ->orderBy('periodo_mes')
                    ->get()
                    ->map(fn ($c) => [
                        'id'         => $c->id,
                        'cotizacion' => $c->cotizacion?->numero ?? '—',
                        'cliente'    => $c->cotizacion?->cliente?->nombreCompleto() ?? '—',
                        'periodo'    => $c->periodo_mes,
                        'estado'     => $c->estado,
                        'total'      => (float) $c->total_comision,
                    ])
                : [],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'user_id'       => 'required|exists:users,id',
            'comisiones'    => 'required|array|min:1',
            'comisiones.*'  => 'integer|exists:comisiones_vendedor,id',
            'fecha'         => 'nullable|date',
            'notas'         => 'nullable|string|max:2000',
        ]);

        $liquidacion = DB::transaction(function () use ($datos) {
            $liquidacion = LiquidacionComision::create([
                'numero'     => LiquidacionComision::generarNumero(),
                'user_id'    => $datos['user_id'],
                'estado'     => 'borrador',
                'fecha'      => $datos['fecha'] ?? today(),
                'notas'      => $datos['notas'] ?? null,
                'creado_por' => auth()->id(),
            ]);

            // Se vuelve a comprobar del lado del servidor: entre que la pantalla cargó y
            // alguien pulsó guardar, otra persona pudo haber liquidado una de esas comisiones.
            $this->pendientes(ComisionVendedor::where('user_id', $datos['user_id']))
                ->whereIn('id', $datos['comisiones'])
                ->update(['liquidacion_id' => $liquidacion->id]);

            $liquidacion->recalcularTotal();

            return $liquidacion;
        });

        return redirect("/comisiones/liquidaciones/{$liquidacion->id}")
            ->with('success', "Liquidación {$liquidacion->numero} creada con {$liquidacion->comisiones()->count()} comisión(es).");
    }

    public function show(LiquidacionComision $liquidacion): Response
    {
        $liquidacion->load([
            'vendedor:id,name', 'creadoPor:id,name',
            'comisiones.cotizacion:id,numero,cliente_id', 'comisiones.cotizacion.cliente:id,nombre,apellido',
        ]);

        return Inertia::render('Comisiones/Liquidaciones/Show', [
            'liquidacion' => [
                'id'        => $liquidacion->id,
                'numero'    => $liquidacion->numero,
                'vendedor'  => $liquidacion->vendedor?->name ?? '—',
                'estado'    => $liquidacion->estado,
                'fecha'     => $liquidacion->fecha?->format('d/m/Y'),
                'notas'     => $liquidacion->notas,
                'total'     => $liquidacion->total,
                'pagada_at' => $liquidacion->pagada_at?->format('d/m/Y H:i'),
                'creada_por'=> $liquidacion->creadoPor?->name,
                'comisiones'=> $liquidacion->comisiones->map(fn ($c) => [
                    'id'         => $c->id,
                    'cotizacion' => $c->cotizacion?->numero ?? '—',
                    'cliente'    => $c->cotizacion?->cliente?->nombreCompleto() ?? '—',
                    'periodo'    => $c->periodo_mes,
                    'total'      => (float) $c->total_comision,
                ]),
            ],
        ]);
    }

    /** Marca el pago. Las comisiones quedan liquidadas y el documento se cierra. */
    public function pagar(LiquidacionComision $liquidacion): RedirectResponse
    {
        if ($liquidacion->estado === 'pagada') {
            return back()->withErrors(['estado' => 'Esta liquidación ya está pagada.']);
        }

        DB::transaction(function () use ($liquidacion) {
            $liquidacion->comisiones()->update(['estado' => 'liquidada', 'liquidada_at' => now()]);
            $liquidacion->update(['estado' => 'pagada', 'pagada_at' => now()]);
        });

        return back()->with('success', 'Liquidación pagada. Sus comisiones quedaron liquidadas.');
    }

    /**
     * Deshace una liquidación en borrador.
     *
     * Sus comisiones vuelven a quedar disponibles. Una pagada no se borra: es el registro de
     * una plata que ya salió, y borrarlo dejaría comisiones liquidadas sin documento que las
     * explique.
     */
    public function destroy(LiquidacionComision $liquidacion): RedirectResponse
    {
        if ($liquidacion->estado === 'pagada') {
            return back()->withErrors(['estado' => 'Una liquidación pagada no se puede deshacer.']);
        }

        DB::transaction(function () use ($liquidacion) {
            $liquidacion->comisiones()->update(['liquidacion_id' => null]);
            $liquidacion->delete();
        });

        return redirect('/comisiones/liquidaciones')->with('success', 'Liquidación deshecha.');
    }

    /** Lo que se puede pagar: sin liquidar, fuera de otra liquidación y con valor. */
    private function pendientes($query)
    {
        return $query->where('estado', '!=', 'liquidada')
            ->whereNull('liquidacion_id')
            ->where('total_comision', '>', 0);
    }
}
