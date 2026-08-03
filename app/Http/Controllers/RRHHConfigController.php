<?php

namespace App\Http\Controllers;

use App\Models\OperarioTarifaExtra;
use App\Models\OperarioTurnoConfig;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class RRHHConfigController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('RRHH/Configuracion/Index', [
            'turnos'   => OperarioTurnoConfig::orderBy('id')->get(),
            'tarifas'  => OperarioTarifaExtra::orderBy('tipo')->get(),
            'config'   => [
                'tarifa_hora_base'     => $this->getConfig('tarifa_hora_base', 15000),
                'penalizacion_ausencia'=> $this->getConfig('penalizacion_ausencia', 0),
            ],
        ]);
    }

    public function storeTurno(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'nombre'      => 'required|string|max:100',
            'hora_inicio' => 'required',
            'hora_fin'    => 'required',
            'activo'      => 'boolean',
        ]);

        if ($request->filled('id')) {
            OperarioTurnoConfig::findOrFail($request->id)->update($data);
        } else {
            OperarioTurnoConfig::create($data);
        }

        return back()->with('success', 'Turno guardado.');
    }

    public function storeTarifa(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'tipo'       => 'required|in:diurna,nocturna,dominical',
            'valor_hora' => 'required|numeric|min:0',
            'activo'     => 'boolean',
        ]);

        OperarioTarifaExtra::updateOrCreate(['tipo' => $data['tipo']], $data);

        return back()->with('success', 'Tarifa guardada.');
    }

    public function saveConfig(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'tarifa_hora_base'     => 'required|numeric|min:0',
            'penalizacion_ausencia'=> 'required|numeric|min:0',
        ]);

        $this->setConfig('tarifa_hora_base', $data['tarifa_hora_base']);
        $this->setConfig('penalizacion_ausencia', $data['penalizacion_ausencia']);

        return back()->with('success', 'Configuración guardada.');
    }

    private function getConfig(string $clave, mixed $default = null): mixed
    {
        if (!$this->configuracionesExiste()) return $default;
        return DB::table('configuraciones')->where('clave', $clave)->value('valor') ?? $default;
    }

    private function setConfig(string $clave, mixed $valor): void
    {
        if (!$this->configuracionesExiste()) {
            DB::statement('CREATE TABLE IF NOT EXISTS configuraciones (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, clave VARCHAR(100) UNIQUE, valor TEXT, created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL)');
        }
        DB::table('configuraciones')->updateOrInsert(['clave' => $clave], ['valor' => $valor, 'updated_at' => now()]);
    }

    private function configuracionesExiste(): bool
    {
        return \Illuminate\Support\Facades\Schema::hasTable('configuraciones');
    }
}
