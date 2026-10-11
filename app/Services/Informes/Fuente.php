<?php

namespace App\Services\Informes;

use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Una fuente de datos de los informes: qué columnas tiene, por qué se puede resumir, qué se
 * cuenta y cómo se sacan las filas.
 *
 * Las filas las calcula PHP con los mismos modelos que las pantallas, respetando la sede activa.
 * La IA nunca escribe una consulta: elige una fuente de este catálogo, el periodo, las columnas
 * y por qué resumir. Así las cifras de un informe pedido en el chat son las mismas que muestran
 * las pantallas, y nadie lee lo que su rol no le deja ver.
 *
 * Tipos de columna: texto, entero, numero, moneda, pct (0–100), fecha (Y-m-d), bool.
 */
abstract class Fuente
{
    abstract public function clave(): string;

    abstract public function etiqueta(): string;

    /** Para qué sirve, en una frase: lo lee la IA para elegir y lo ve la persona al armar. */
    abstract public function descripcion(): string;

    /** El permiso de la pantalla de donde salen los datos. */
    abstract public function permiso(): string;

    /** Qué fecha filtra el periodo, dicho para personas. */
    abstract public function sobreQueFecha(): string;

    /** @return array<string, array{0:string, 1:string}> clave => [etiqueta, tipo] */
    abstract public function columnas(): array;

    /** La columna de fecha con la que se arma el resumen «por mes». */
    abstract public function columnaFecha(): string;

    /** @return array<string, string> por qué se puede resumir: clave de columna (o 'mes') => etiqueta */
    abstract public function dimensiones(): array;

    /**
     * Lo que se calcula en cada resumen.
     *
     * @return array<string, array{etiqueta:string, agregado:string, columna?:string, tipo:string}>
     *   agregado: contar | sumar | promedio | tasa (porcentaje de filas en que `columna` es verdadera)
     */
    abstract public function medidas(): array;

    /**
     * Filtros propios además del periodo.
     *
     * @return array<string, array{etiqueta:string, opciones: array<string,string>}>
     */
    public function filtros(): array
    {
        return [];
    }

    /**
     * Todas las filas del periodo, con todas las columnas.
     *
     * @return array<int, array<string, mixed>>
     */
    abstract public function filas(?Carbon $desde, ?Carbon $hasta, array $filtros): array;

    /**
     * Las cifras clave del informe, ya calculadas.
     *
     * @param  array<int, array<string, mixed>>  $filas
     * @return array<int, array{clave:string, etiqueta:string, valor: int|float|string|null, tipo:string}>
     */
    abstract public function metricas(array $filas): array;

    /** Columnas que la persona no puede ver aunque vea la fuente (el costo, sin `costos.ver`). */
    public function columnasOcultas(?User $usuario): array
    {
        return [];
    }

    public function columnasPorDefecto(): array
    {
        return array_keys($this->columnas());
    }

    public function permitida(?User $usuario): bool
    {
        return (bool) $usuario?->tienePermiso($this->permiso());
    }

    // ─── Ayudas para las fuentes ─────────────────────────────────────────────

    protected function metrica(string $clave, string $etiqueta, int|float|string|null $valor, string $tipo = 'entero'): array
    {
        return compact('clave', 'etiqueta', 'valor', 'tipo');
    }

    protected static function pct(int|float $parte, int|float $total): ?float
    {
        return $total > 0 ? round($parte * 100 / $total, 1) : null;
    }

    protected static function dias(?\DateTimeInterface $desde, ?\DateTimeInterface $hasta): ?int
    {
        if (! $desde || ! $hasta) {
            return null;
        }

        return (int) floor((Carbon::parse($hasta)->getTimestamp() - Carbon::parse($desde)->getTimestamp()) / 86400);
    }

    /** Las opciones de un filtro por vendedor: los usuarios activos. */
    protected static function opcionesUsuarios(): array
    {
        return User::where('activo', true)->orderBy('name')->pluck('name', 'id')->map(fn ($n) => (string) $n)->all();
    }
}
