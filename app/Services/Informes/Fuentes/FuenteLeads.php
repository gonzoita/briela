<?php

namespace App\Services\Informes\Fuentes;

use App\Models\CrmLead;
use App\Models\CrmLeadOrigen;
use App\Services\Informes\Fuente;
use App\Support\ContextoSede;
use Illuminate\Support\Carbon;

/**
 * Los leads que llegaron en el periodo y qué pasó con ellos: por dónde entraron, quién los
 * atendió, si se cotizaron, cuánto se tardó en cotizar y si se ganaron.
 *
 * Es la fuente para «¿cuántos clientes llegaron y cuántos convertimos?». Los días hasta la
 * primera cotización y los días sin movimiento son lo que más dice de la atención.
 */
class FuenteLeads extends Fuente
{
    public function clave(): string { return 'leads'; }

    public function etiqueta(): string { return 'Leads del CRM'; }

    public function descripcion(): string
    {
        return 'Clientes potenciales que llegaron (por canal y vendedor), si se cotizaron, en cuántos días, '
            .'si se ganaron o perdieron y por qué. Sirve para conversión y calidad de la atención.';
    }

    public function permiso(): string { return 'crm.ver'; }

    public function sobreQueFecha(): string { return 'la fecha en que llegó el lead'; }

    public function columnaFecha(): string { return 'fecha'; }

    public function columnas(): array
    {
        return [
            'fecha'               => ['Llegó', 'fecha'],
            'lead'                => ['Lead', 'texto'],
            'contacto'            => ['Contacto', 'texto'],
            'empresa'             => ['Empresa', 'texto'],
            'telefono'            => ['Teléfono', 'texto'],
            'correo'              => ['Correo', 'texto'],
            'canal'               => ['Canal', 'texto'],
            'vendedor'            => ['Vendedor', 'texto'],
            'etapa'               => ['Etapa', 'texto'],
            'estado'              => ['Estado', 'texto'],
            'cotizo'              => ['Se cotizó', 'bool'],
            'cotizaciones'        => ['Cotizaciones', 'entero'],
            'valor_cotizado'      => ['Valor cotizado', 'moneda'],
            'ganado'              => ['Ganado', 'bool'],
            'valor_ganado'        => ['Valor ganado', 'moneda'],
            'dias_a_cotizar'      => ['Días hasta cotizar', 'entero'],
            'dias_sin_movimiento' => ['Días sin movimiento', 'entero'],
            'motivo_cierre'       => ['Motivo de cierre', 'texto'],
        ];
    }

    public function columnasPorDefecto(): array
    {
        return ['fecha', 'lead', 'contacto', 'canal', 'vendedor', 'estado', 'cotizo', 'valor_cotizado',
            'ganado', 'dias_a_cotizar', 'dias_sin_movimiento', 'motivo_cierre'];
    }

    public function dimensiones(): array
    {
        return ['canal' => 'Canal', 'vendedor' => 'Vendedor', 'etapa' => 'Etapa', 'estado' => 'Estado', 'mes' => 'Mes'];
    }

    public function medidas(): array
    {
        return [
            'leads'          => ['etiqueta' => 'Leads', 'agregado' => 'contar', 'tipo' => 'entero'],
            'pct_cotizados'  => ['etiqueta' => '% cotizados', 'agregado' => 'tasa', 'columna' => 'cotizo', 'tipo' => 'pct'],
            'pct_ganados'    => ['etiqueta' => '% ganados', 'agregado' => 'tasa', 'columna' => 'ganado', 'tipo' => 'pct'],
            'dias_cotizar'   => ['etiqueta' => 'Días promedio hasta cotizar', 'agregado' => 'promedio', 'columna' => 'dias_a_cotizar', 'tipo' => 'numero'],
            'valor_cotizado' => ['etiqueta' => 'Valor cotizado', 'agregado' => 'sumar', 'columna' => 'valor_cotizado', 'tipo' => 'moneda'],
            'valor_ganado'   => ['etiqueta' => 'Valor ganado', 'agregado' => 'sumar', 'columna' => 'valor_ganado', 'tipo' => 'moneda'],
        ];
    }

    public function filtros(): array
    {
        return [
            'estado'   => ['etiqueta' => 'Estado', 'opciones' => ['activo' => 'Abiertos', 'ganado' => 'Ganados', 'perdido' => 'Perdidos']],
            'canal'    => ['etiqueta' => 'Canal', 'opciones' => collect(CrmLeadOrigen::canales())->map(fn ($c) => $c['etiqueta'])->all()],
            'vendedor' => ['etiqueta' => 'Vendedor', 'opciones' => self::opcionesUsuarios()],
        ];
    }

    public function filas(?Carbon $desde, ?Carbon $hasta, array $filtros): array
    {
        $canales = CrmLeadOrigen::canales();

        $leads = ContextoSede::aplicar(CrmLead::query())
            ->with([
                'etapa:id,nombre', 'responsable:id,name', 'cliente:id,nombre,apellido',
                'origenes' => fn ($q) => $q->orderBy('created_at'),
                'cotizaciones' => fn ($q) => $q->orderBy('created_at'),
            ])
            ->withMax('actividades', 'created_at')
            ->when($desde, fn ($q) => $q->where('created_at', '>=', $desde))
            ->when($hasta, fn ($q) => $q->where('created_at', '<=', $hasta))
            ->when($filtros['estado'] ?? null, fn ($q, $v) => $q->where('estado', $v))
            ->when($filtros['vendedor'] ?? null, fn ($q, $v) => $q->where('responsable_id', $v))
            ->when($filtros['canal'] ?? null, fn ($q, $v) => $q->whereHas('origenes', fn ($o) => $o->where('canal', $v)))
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $ahora = now();

        return $leads->map(function (CrmLead $l) use ($canales, $ahora) {
            $cots     = $l->cotizaciones;
            $primera  = $cots->first();
            $ultima   = $cots->last();
            $ganadas  = $cots->filter(fn ($c) => $c->estado === 'aprobada' || $c->en_produccion);
            $canal    = $l->origenes->first()?->canal;
            $ultimoMovimiento = collect([$l->actividades_max_created_at, $ultima?->updated_at, $l->updated_at])
                ->filter()->map(fn ($f) => Carbon::parse($f))->max();
            $cerrado  = in_array($l->estado, ['ganado', 'perdido'], true);

            return [
                'fecha'               => $l->created_at?->toDateString(),
                'lead'                => $l->titulo,
                'contacto'            => $l->nombre_contacto,
                'empresa'             => $l->empresa_contacto ?: $l->cliente?->nombreCompleto(),
                'telefono'            => $l->telefono_contacto,
                'correo'              => $l->email_contacto,
                'canal'               => $canal ? ($canales[$canal]['etiqueta'] ?? ucfirst($canal)) : ($l->fuente ?: 'Sin canal'),
                'vendedor'            => $l->responsable?->name ?? 'Sin asignar',
                'etapa'               => $l->etapa?->nombre,
                'estado'              => ['activo' => 'Abierto', 'ganado' => 'Ganado', 'perdido' => 'Perdido'][$l->estado] ?? $l->estado,
                'cotizo'              => $cots->isNotEmpty(),
                'cotizaciones'        => $cots->count(),
                // La versión vigente, no la suma: una cotización corregida tres veces no es triple.
                'valor_cotizado'      => $ultima ? (float) $ultima->total : 0.0,
                'ganado'              => $l->estado === 'ganado',
                'valor_ganado'        => (float) $ganadas->sum('total'),
                'dias_a_cotizar'      => $primera ? max(0, self::dias($l->created_at, $primera->created_at)) : null,
                // Un lead cerrado no está «quieto»: ya terminó.
                'dias_sin_movimiento' => $cerrado ? null : self::dias($ultimoMovimiento, $ahora),
                'motivo_cierre'       => $l->motivo_cierre,
            ];
        })->all();
    }

    public function metricas(array $filas): array
    {
        $n         = count($filas);
        $cotizados = count(array_filter($filas, fn ($f) => $f['cotizo']));
        $ganados   = count(array_filter($filas, fn ($f) => $f['ganado']));
        $perdidos  = count(array_filter($filas, fn ($f) => $f['estado'] === 'Perdido'));
        $sinCotizar = count(array_filter($filas, fn ($f) => ! $f['cotizo'] && $f['estado'] === 'Abierto'));
        $quietos   = count(array_filter($filas, fn ($f) => ($f['dias_sin_movimiento'] ?? 0) >= 7));
        $dias      = array_filter(array_column($filas, 'dias_a_cotizar'), fn ($d) => $d !== null);

        // El canal que mejor convierte, con al menos 3 leads para que no gane uno con suerte.
        $porCanal = collect($filas)->groupBy('canal')->filter(fn ($g) => $g->count() >= 3)
            ->map(fn ($g) => $g->where('ganado', true)->count() / $g->count())->sortDesc();

        return [
            $this->metrica('leads', 'Leads que llegaron', $n),
            $this->metrica('cotizados', 'Se cotizaron', $cotizados),
            $this->metrica('pct_cotizados', '% que se cotizó', self::pct($cotizados, $n), 'pct'),
            $this->metrica('ganados', 'Ganados', $ganados),
            $this->metrica('conversion', 'Conversión (ganados / leads)', self::pct($ganados, $n), 'pct'),
            $this->metrica('cierre_cotizados', 'Cierre de lo cotizado', self::pct($ganados, $cotizados), 'pct'),
            $this->metrica('perdidos', 'Perdidos', $perdidos),
            $this->metrica('sin_cotizar', 'Abiertos sin cotizar', $sinCotizar),
            $this->metrica('quietos', 'Abiertos sin movimiento hace 7 días o más', $quietos),
            $this->metrica('dias_cotizar', 'Días promedio hasta cotizar', $dias ? round(array_sum($dias) / count($dias), 1) : null, 'numero'),
            $this->metrica('valor_cotizado', 'Valor cotizado', round(array_sum(array_column($filas, 'valor_cotizado')), 2), 'moneda'),
            $this->metrica('valor_ganado', 'Valor ganado', round(array_sum(array_column($filas, 'valor_ganado')), 2), 'moneda'),
            $this->metrica('mejor_canal', 'Canal que mejor convierte', $porCanal->keys()->first(), 'texto'),
        ];
    }
}
