<?php

namespace App\Http\Controllers;

use App\Models\Configuracion;
use App\Models\Producto;
use App\Models\TasaCambio;
use App\Services\CostosEnMonedaService;
use App\Services\TasaCambioService;
use App\Support\Monedas;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Configuración → Monedas: las tasas del día, cómo se usan, y la salida a mano.
 */
class MonedasConfigController extends Controller
{
    public function __construct(
        private TasaCambioService $tasas,
        private CostosEnMonedaService $costos,
    ) {}

    public function index(): Response
    {
        return Inertia::render('Configuracion/Monedas', [
            'monedas'   => collect(Monedas::CATALOGO)->except(Monedas::base())
                ->map(fn ($m, $codigo) => [
                    'codigo'     => $codigo,
                    'nombre'     => $m['nombre'],
                    'automatica' => in_array($codigo, $this->tasas->automaticas(), true),
                ])->values(),
            'base'      => ['codigo' => Monedas::base(), 'nombre' => Monedas::CATALOGO[Monedas::base()]['nombre'], 'bloqueada' => Monedas::baseBloqueada()],
            'catalogo_base' => collect(Monedas::CATALOGO)->map(fn ($m, $c) => ['codigo' => $c, 'nombre' => $m['nombre']])->values(),
            'tasas'     => $this->tasas->paraInterfaz(),
            'historial' => TasaCambio::orderByDesc('fecha')->orderBy('moneda')->limit(20)
                ->get(['moneda', 'fecha', 'valor', 'fuente'])
                ->map(fn ($t) => [
                    'moneda' => $t->moneda,
                    'fecha'  => $t->fecha->toDateString(),
                    'valor'  => (float) $t->valor,
                    'fuente' => $t->fuente,
                ]),
            'ajustes'   => [
                'colchon_pct' => Monedas::colchonPct(),
                'modo'        => Monedas::modoCotizacion(),
            ],
            'productos_en_moneda' => Producto::where('moneda_costo', '!=', Monedas::base())->count(),
        ]);
    }

    public function guardar(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'colchon_pct' => 'required|numeric|min:0|max:50',
            'modo'        => 'required|in:fija,diaria',
        ]);

        $colchonAntes = Monedas::colchonPct();

        Configuracion::set('monedas_colchon_pct', (string) $datos['colchon_pct']);
        Configuracion::set('monedas_modo_cotizacion', $datos['modo']);

        // El colchón entra en el costo: cambiarlo cambia el costo de todo lo que se compra
        // en otra moneda, y eso tiene que verse ya, no mañana a las seis.
        $productos = abs($colchonAntes - (float) $datos['colchon_pct']) > 0.0001
            ? $this->costos->actualizarProductos()
            : 0;

        $this->costos->actualizarCotizacionesAbiertas();

        return back()->with('success', $productos > 0
            ? "Ajustes guardados. Se recalculó el costo de {$productos} producto(s)."
            : 'Ajustes guardados.');
    }

    /** «Actualizar ahora»: lo mismo que hace la tarea programada. */
    public function actualizar(): RedirectResponse
    {
        $resultado = $this->tasas->actualizar();
        $productos = $this->costos->actualizarProductos();
        $this->costos->actualizarCotizacionesAbiertas();

        $fallidas = collect($resultado)->reject(fn ($r) => $r['ok']);

        if ($fallidas->isNotEmpty()) {
            return back()->with('error', $fallidas->map(fn ($r, $m) => "{$m}: {$r['mensaje']}")->implode(' ')
                . ' Puedes escribir la tasa a mano mientras tanto.');
        }

        return back()->with('success', 'Tasas actualizadas.' . ($productos > 0 ? " Se recalculó el costo de {$productos} producto(s)." : ''));
    }

    /**
     * Cambia la moneda base. Solo mientras no haya cotizaciones ni órdenes: con documentos
     * guardados, cada monto antiguo quedaría escrito en una moneda que ya no es la base.
     */
    public function cambiarBase(Request $request): RedirectResponse
    {
        $datos = $request->validate(['moneda' => 'required|in:' . implode(',', array_keys(Monedas::CATALOGO))]);

        if (Monedas::baseBloqueada()) {
            return back()->with('error', 'Ya hay cotizaciones u órdenes guardadas: la moneda base no se puede cambiar.');
        }

        Configuracion::set('moneda_base', $datos['moneda']);
        // Productos con costo en la moneda que ahora es la base ya no están «en otra moneda».
        Producto::where('moneda_costo', $datos['moneda'])->update(['costo_moneda' => null]);

        return back()->with('success', "La moneda base ahora es {$datos['moneda']}. Revisa las tasas de las demás monedas.");
    }

    /** Una tasa escrita a mano, para el día de hoy. */
    public function manual(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'moneda' => 'required|in:' . implode(',', Monedas::extranjeras()),
            'valor'  => 'required|numeric|min:0.00000001|max:100000000',
        ]);

        $this->tasas->registrarManual($datos['moneda'], (float) $datos['valor']);
        $productos = $this->costos->actualizarProductos();
        $this->costos->actualizarCotizacionesAbiertas();

        return back()->with('success', "Tasa de {$datos['moneda']} guardada para hoy."
            . ($productos > 0 ? " Se recalculó el costo de {$productos} producto(s)." : ''));
    }
}
