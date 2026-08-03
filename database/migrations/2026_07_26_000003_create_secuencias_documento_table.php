<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Numeración configurable por sede y por tipo de documento. Reemplaza los
    // consecutivos que hoy cada modelo calcula por su cuenta (y que además
    // pueden chocar si se crean dos documentos en el mismo instante).
    public function up(): void
    {
        Schema::create('secuencias_documento', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sede_id')->nullable()->constrained('sedes')->cascadeOnDelete();
            $table->string('tipo_documento', 40); // op, cotizacion, remision, ...
            $table->string('prefijo', 30)->default('');
            $table->boolean('incluir_anio')->default(false); // ej. COT-2026-001
            $table->unsignedInteger('siguiente_numero')->default(1);
            $table->unsignedTinyInteger('padding')->default(4); // cantidad de ceros
            $table->timestamps();

            $table->unique(['sede_id', 'tipo_documento'], 'secuencia_sede_tipo_unica');
        });

        $sedes = DB::table('sedes')->get();
        if ($sedes->isEmpty()) {
            return;
        }

        // Tipos de documento con su formato actual, para no romper lo que ya
        // existe. Para la sede principal se arranca el consecutivo justo
        // después del último número usado hoy.
        $tipos = [
            ['tipo' => 'op',               'prefijo' => 'OP-',  'anio' => false, 'padding' => 4, 'tabla' => 'ops',                'columna' => 'numero'],
            ['tipo' => 'cotizacion',       'prefijo' => 'COT-', 'anio' => true,  'padding' => 3, 'tabla' => 'cotizaciones',       'columna' => 'numero'],
            ['tipo' => 'remision',         'prefijo' => 'REM-', 'anio' => false, 'padding' => 4, 'tabla' => 'remisiones',         'columna' => 'numero'],
            ['tipo' => 'solicitud_compra', 'prefijo' => 'SC-',  'anio' => true,  'padding' => 3, 'tabla' => 'solicitudes_compra', 'columna' => 'numero'],
            ['tipo' => 'orden_compra',     'prefijo' => 'OC-',  'anio' => true,  'padding' => 3, 'tabla' => 'ordenes_compra',     'columna' => 'numero'],
            ['tipo' => 'serie_item',       'prefijo' => 'IF-',  'anio' => true,  'padding' => 3, 'tabla' => null,                 'columna' => null],
        ];

        $filas = [];

        foreach ($sedes as $sede) {
            foreach ($tipos as $t) {
                $siguiente = 1;

                // Solo la sede principal hereda los consecutivos ya usados;
                // las sedes nuevas arrancan en 1. Se toma el número más alto
                // ya emitido (último tramo del código, después del guion) en
                // vez de contar filas — así no se repite un número si alguna
                // vez se borró un documento.
                if ($sede->es_principal && $t['tabla'] && Schema::hasTable($t['tabla'])) {
                    $ultimo = DB::table($t['tabla'])
                        ->selectRaw("MAX(CAST(SUBSTRING_INDEX({$t['columna']}, '-', -1) AS UNSIGNED)) AS maximo")
                        ->value('maximo');
                    $siguiente = ((int) $ultimo) + 1;
                }

                // El prefijo por defecto de las sedes no principales lleva su
                // código adelante (ej. "CAL-OP-") para que no choquen entre sí.
                $prefijo = $sede->es_principal
                    ? $t['prefijo']
                    : $sede->codigo . '-' . $t['prefijo'];

                $filas[] = [
                    'sede_id'          => $sede->id,
                    'tipo_documento'   => $t['tipo'],
                    'prefijo'          => $prefijo,
                    'incluir_anio'     => $t['anio'],
                    'siguiente_numero' => $siguiente,
                    'padding'          => $t['padding'],
                    'created_at'       => now(),
                    'updated_at'       => now(),
                ];
            }
        }

        DB::table('secuencias_documento')->insert($filas);
    }

    public function down(): void
    {
        Schema::dropIfExists('secuencias_documento');
    }
};
