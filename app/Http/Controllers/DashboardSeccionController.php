<?php

namespace App\Http\Controllers;

use App\Models\DashboardSeccion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Las secciones del tablero de inicio.
 *
 * Solo agrupan y ordenan: los gráficos de cada sección los sigue manejando
 * `GraficoDashboardController`, y los datos, `FuentesGraficoService`. Aquí no se calcula nada.
 *
 * Crear, renombrar, mover y borrar exige `graficos.gestionar`, igual que armar un gráfico: lo
 * que alguien agrega **lo ven todos**, así que no es una preferencia personal sino una decisión
 * de la empresa sobre qué se mira al entrar.
 *
 * Responde con `back()` —no con JSON— porque las pantallas son de Inertia: así la sección nueva
 * llega en la misma respuesta y no hace falta una segunda vuelta al servidor para verla.
 */
class DashboardSeccionController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'titulo' => ['required', 'string', 'max:80'],
        ]);

        DashboardSeccion::create([
            'titulo'     => $datos['titulo'],
            'clave'      => DashboardSeccion::generarClave($datos['titulo']),
            // Al final de las que ya hay: una sección nueva no se mete en medio de un tablero
            // que alguien ya ordenó.
            'orden'      => (int) DashboardSeccion::max('orden') + 1,
            'creado_por' => auth()->id(),
        ]);

        return back()->with('success', 'Sección agregada al tablero.');
    }

    /** Renombrar. La clave no se toca: los gráficos de la sección cuelgan de ella. */
    public function update(Request $request, DashboardSeccion $seccion): RedirectResponse
    {
        $datos = $request->validate([
            'titulo' => ['required', 'string', 'max:80'],
        ]);

        $seccion->update(['titulo' => $datos['titulo']]);

        return back()->with('success', 'Sección renombrada.');
    }

    /**
     * Subir o bajar una sección, intercambiándola con su vecina.
     *
     * Mover de a un puesto en vez de arrastrar: el tablero se ve igual en celular que en
     * computador, y arrastrar con el dedo pelea con el desplazamiento de la página.
     */
    public function mover(Request $request, DashboardSeccion $seccion): RedirectResponse
    {
        $datos = $request->validate([
            'direccion' => ['required', 'in:arriba,abajo'],
        ]);

        $orden = DashboardSeccion::orderBy('orden')->orderBy('id')->get();
        $pos   = $orden->search(fn ($s) => $s->id === $seccion->id);

        if ($pos === false) {
            return back();
        }

        $destino = $datos['direccion'] === 'arriba' ? $pos - 1 : $pos + 1;

        // Ya está en la punta: no hay con quién intercambiar.
        if ($destino < 0 || $destino >= $orden->count()) {
            return back();
        }

        // Se reescribe la columna entera con la posición en la lista. Las filas viejas pueden
        // traer todas `orden = 0` —quedaron así antes de que existiera este botón—, y sobre
        // ceros repetidos intercambiar dos valores no mueve nada.
        $orden->splice($pos, 1);
        $orden->splice($destino, 0, [$seccion]);

        foreach ($orden->values() as $i => $s) {
            DashboardSeccion::whereKey($s->id)->update(['orden' => $i]);
        }

        return back();
    }

    public function destroy(DashboardSeccion $seccion): RedirectResponse
    {
        // Se lleva sus gráficos: lo hace el modelo, para que borrar por cualquier camino
        // limpie igual.
        $seccion->delete();

        return back()->with('success', 'Sección eliminada.');
    }
}
