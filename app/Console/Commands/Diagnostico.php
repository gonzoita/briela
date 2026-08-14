<?php

namespace App\Console\Commands;

use App\Models\Ensamble;
use App\Models\Producto;
use App\Models\SegmentacionOpcion;
use App\Services\CanalesPrecioService;
use App\Services\PreciosPorCanalService;
use Illuminate\Console\Command;

/**
 * Imprime, de una sola vez, todo lo que hace falta para entender por qué un precio no sale
 * como debería.
 *
 * Existe por una razón muy concreta: el problema de precios del 13 ago 2026 costó cinco
 * intentos porque cada hipótesis exigía un viaje de ida y vuelta —«corre esto y dime qué
 * dice»—. Los datos que hacían falta eran siempre los mismos: qué canales hay, con qué
 * clave y qué papel, qué filas tiene el producto, qué hay en las columnas antiguas, y qué
 * precio recibiría la cotización para cada canal.
 *
 * Con esto, eso es un comando y una salida. Sirve igual para atender a un cliente por
 * teléfono: no toca nada, solo lee.
 */
class Diagnostico extends Command
{
    protected $signature = 'briela:diagnostico
                            {referencia? : Referencia del producto, o ENS-12 para un ensamble}
                            {--cliente= : Identificación o nombre de un cliente, para ver con qué canal se le cotiza}';

    protected $description = 'Muestra el estado de los canales de precio y qué precio recibiría una cotización';

    public function handle(CanalesPrecioService $canalesSvc, PreciosPorCanalService $precios): int
    {
        $this->line('');
        $this->info('═══ CANALES DE PRECIO CONFIGURADOS ═══');

        $canales = $canalesSvc->canales();

        if ($canales->isEmpty()) {
            $this->error('No hay ningún canal de precio. Ninguna cotización va a mostrar precios.');
            $this->line('Se arregla marcando «define precio» en Configuración → Listas de segmentación.');
        } else {
            $this->table(
                ['Orden', 'Etiqueta', 'Clave interna', 'Papel', 'Margen sug.', 'Columna antigua'],
                $canales->map(fn (SegmentacionOpcion $c) => [
                    $c->orden,
                    $c->etiqueta,
                    $c->valor,
                    $c->es_canal_base ? 'canal base' : ($c->es_precio_publico ? 'precio público' : '—'),
                    $c->margen_sugerido.'%',
                    $precios->columnaDe($c) ?? 'ninguna',
                ])->all()
            );

            if (! $canalesSvc->base()) {
                $this->warn('Falta marcar un canal como CANAL BASE: las comisiones se calculan contra su precio.');
            }

            if (! $canalesSvc->publico()) {
                $this->warn('Falta marcar un canal como PRECIO PÚBLICO: es el respaldo cuando el cliente no está '
                    .'segmentado, y el precio del catálogo web.');
            }
        }

        // ─── Los tipos de contacto que NO definen precio, que es la confusión más común
        $sinPrecio = SegmentacionOpcion::where('tipo', 'tipo_contacto')
            ->where('activo', true)->where('define_precio', false)->get();

        if ($sinPrecio->isNotEmpty()) {
            $this->line('');
            $this->line('Tipos de contacto activos que NO definen precio (un cliente con solo estos');
            $this->line('se cotiza con el precio público):');

            foreach ($sinPrecio as $s) {
                $this->line("  · {$s->etiqueta}  ({$s->valor})");
            }
        }

        if ($referencia = $this->argument('referencia')) {
            $this->diagnosticarItem($referencia, $canales, $precios);
        }

        if ($cliente = $this->option('cliente')) {
            $this->diagnosticarCliente($cliente, $canales);
        }

        $this->line('');

        return self::SUCCESS;
    }

    private function diagnosticarItem(string $referencia, $canales, PreciosPorCanalService $precios): void
    {
        $esEnsamble = str_starts_with(strtoupper($referencia), 'ENS-');

        $item = $esEnsamble
            ? Ensamble::find((int) substr($referencia, 4))
            : Producto::where('referencia', $referencia)->first();

        if (! $item) {
            $this->line('');
            $this->error("No existe «{$referencia}». Para un ensamble se escribe ENS-12.");

            return;
        }

        $item->load('preciosPorCanal');

        $this->line('');
        $this->info('═══ '.mb_strtoupper($item->nombre).' ═══');
        $this->line('Costo: '.number_format((float) $item->precio_costo, 0, ',', '.'));

        $this->line('');
        $this->line('Columnas antiguas guardadas en la base:');
        foreach (['mayorista', 'distribuidor', 'cliente_final'] as $columna) {
            $this->line("  precio_{$columna}: ".number_format((float) ($item->{"precio_{$columna}"} ?? 0), 0, ',', '.'));
        }

        $this->line('');
        $this->line('Filas por canal guardadas: '.$item->preciosPorCanal->count());

        $this->line('');
        $this->line('Lo que recibiría una cotización para cada canal:');

        $filas = [];

        foreach ($canales as $canal) {
            $fila     = $precios->filaEfectiva($item, $canal);
            $guardada = $item->preciosPorCanal->firstWhere('segmentacion_opcion_id', $canal->id);

            $origen = match (true) {
                $fila['precio'] <= 0            => 'SIN PRECIO ← hay que cargarlo',
                $fila['desde_columnas_viejas']  => 'de la columna antigua',
                default                         => 'de su fila',
            };

            $filas[] = [
                $canal->etiqueta,
                number_format($fila['precio'], 0, ',', '.'),
                $guardada ? number_format((float) $guardada->precio, 0, ',', '.') : 'no tiene fila',
                $fila['comision_min_pct'].'–'.$fila['comision_max_pct'].'%',
                $fila['descuento_max_pct'].'%',
                $origen,
            ];
        }

        $this->table(['Canal', 'Precio efectivo', 'En su fila', 'Comisión', 'Desc. máx', 'De dónde sale'], $filas);

        if (! $item->descripcion_cotizacion) {
            $this->warn('Sin resumen técnico: la cotización imprimirá la descripción comercial, y el '
                .'asistente no puede recomendar este ítem.');
        }
    }

    private function diagnosticarCliente(string $busqueda, $canales): void
    {
        $cliente = \App\Models\Cliente::where('numero_identificacion', $busqueda)
            ->orWhere('nombre', 'like', "%{$busqueda}%")
            ->first();

        if (! $cliente) {
            $this->line('');
            $this->error("No se encontró un cliente con «{$busqueda}».");

            return;
        }

        $tipos = (array) $cliente->tipos_contacto;

        $this->line('');
        $this->info('═══ CLIENTE: '.mb_strtoupper($cliente->nombre).' ═══');
        $this->line('Tipos de contacto: '.($tipos ? implode(', ', $tipos) : 'ninguno'));

        $propio = $canales->first(fn ($c) => in_array($c->valor, $tipos, true));

        if ($propio) {
            $this->line("Se le cotiza con: {$propio->etiqueta} (el canal que le corresponde)");

            return;
        }

        $publico = $canales->firstWhere('es_precio_publico', true);

        $this->warn($tipos
            ? 'Ninguno de sus tipos define precio.'
            : 'No está segmentado.');

        $this->line($publico
            ? "Se le cotiza con: {$publico->etiqueta} (el precio público, por omisión)"
            : 'Y no hay canal marcado como precio público: se quedaría sin precio.');
    }
}
