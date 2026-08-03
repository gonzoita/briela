<?php

namespace App\Services\IA;

use App\Models\Cliente;
use App\Models\Cotizacion;
use App\Models\CotizacionItem;
use App\Models\Producto;
use App\Support\ContextoSede;
use Illuminate\Support\Facades\DB;

/**
 * Acciones que el asistente puede EJECUTAR (no solo consultar).
 *
 * Va aparte de ConsultasDatosService a propósito: aquello es de solo lectura,
 * esto escribe en la base. La separación hace evidente qué puede modificar.
 *
 * Reglas que no se rompen:
 *  - Todo lo que crea nace en BORRADOR. Nada queda listo para enviarse a un
 *    cliente sin que una persona lo haya revisado.
 *  - Los PRECIOS los pone el sistema desde el catálogo, nunca la IA. La IA
 *    elige qué productos y cuántos; el cuánto vale sale de la base.
 *  - Si algo es ambiguo (cliente que no existe, producto que no aparece), NO
 *    inventa ni crea: devuelve qué falta para que el usuario decida.
 */
class AccionesIaService
{
    /**
     * Catálogo de acciones, con el permiso que exige cada una.
     */
    public static function catalogo(): array
    {
        return [
            'crear_cotizacion' => [
                'descripcion' => 'Crea una cotización EN BORRADOR para un cliente, con los productos y cantidades indicados. Úsala cuando pidan "hazme una cotización", "cotiza X para Y". Los precios los pone el sistema.',
                'parametros'  => [
                    'cliente' => 'nombre, apellido, nombre completo o número de identificación (NIT/cédula) del cliente (obligatorio)',
                    'items'   => 'lista de {producto: "nombre o referencia", cantidad: número}',
                    'notas'   => 'observaciones internas (opcional)',
                ],
                'permiso'     => 'cotizaciones.crear',
            ],
        ];
    }

    public function disponibles(): array
    {
        $user = auth()->user();

        return collect(static::catalogo())
            ->filter(fn ($cfg) => $user?->tienePermiso($cfg['permiso']))
            ->all();
    }

    /**
     * @return array|null null si la acción no existe o no está permitida
     */
    public function ejecutar(string $accion, array $parametros = []): ?array
    {
        if (! array_key_exists($accion, $this->disponibles())) {
            return null;
        }

        return match ($accion) {
            'crear_cotizacion' => $this->crearCotizacion($parametros),
            default            => null,
        };
    }

    /**
     * Busca clientes por nombre, apellido, nombre completo o identificación.
     *
     * Antes solo miraba `nombre`, y eso dejaba fuera dos casos normales:
     * una persona guardada como nombre "Diego" + apellido "González" no
     * aparecía al pedir "Diego González", y el número de identificación
     * (NIT/cédula) no se podía usar para buscar aunque es el dato menos
     * ambiguo que existe.
     */
    private function buscarClientes(string $texto, $query): \Illuminate\Database\Eloquent\Collection
    {
        $texto = trim($texto);

        // Solo dígitos: se trata como identificación (NIT/cédula), donde una
        // coincidencia parcial no tiene sentido y confunde más de lo que ayuda.
        $soloDigitos = preg_replace('/\D+/', '', $texto);
        if ($soloDigitos !== '' && $soloDigitos === preg_replace('/[\s.\-]+/', '', $texto)) {
            return $query->where('numero_identificacion', $soloDigitos)
                ->limit(5)
                ->get();
        }

        return $query
            ->where(function ($q) use ($texto) {
                $q->where('nombre', 'like', "%{$texto}%")
                  ->orWhere('apellido', 'like', "%{$texto}%")
                  ->orWhereRaw("CONCAT(COALESCE(nombre,''), ' ', COALESCE(apellido,'')) LIKE ?", ["%{$texto}%"])
                  ->orWhere('numero_identificacion', 'like', "%{$texto}%");
            })
            ->limit(5)
            ->get();
    }

    /**
     * Arma una cotización en borrador.
     *
     * Devuelve siempre un array con 'exito' para que el asistente sepa si
     * cuenta un logro o pide algo que falta.
     */
    private function crearCotizacion(array $p): array
    {
        $nombreCliente = trim((string) ($p['cliente'] ?? ''));
        $items         = $p['items'] ?? [];

        if ($nombreCliente === '') {
            return ['exito' => false, 'falta' => 'No me dijiste para qué cliente es la cotización.'];
        }

        if (! is_array($items) || empty($items)) {
            return ['exito' => false, 'falta' => 'No me dijiste qué productos van en la cotización.'];
        }

        // ── Cliente: se busca, NO se crea ─────────────────────────────────────
        $candidatos = $this->buscarClientes($nombreCliente, ContextoSede::aplicar(Cliente::query()));

        if ($candidatos->isEmpty()) {
            // Antes se respondía "no existe" a secas. Pero el filtro de sede
            // solo mira `clientes.sede_id`, mientras el resumen de clientes
            // agrupa COTIZACIONES por sede — así que un cliente de otra sede
            // con cotizaciones de esta aparecía en el resumen y "no existía"
            // al cotizar. Se revisa fuera de la sede para decir la verdad
            // completa en vez de mandar a buscar un dato que sí está.
            //
            // Solo se miran las sedes a las que el usuario TIENE acceso: la
            // separación por sede es deliberada, y avisar de un cliente de una
            // sede ajena filtraría datos que esa persona no debería ver.
            $sedesPropias = auth()->user()?->sedesAccesibles()->pluck('id')->all() ?? [];

            // Se incluyen los que quedaron SIN sede: no son un caso de otra
            // sede sino un dato incompleto, y son invisibles para el filtro
            // normal — justo el tipo de cliente que "existe pero no aparece".
            $fuera = $this->buscarClientes(
                $nombreCliente,
                Cliente::query()->where(
                    fn ($q) => $q->whereIn('sede_id', $sedesPropias)->orWhereNull('sede_id')
                )
            )->load('sede:id,nombre');

            if ($fuera->isNotEmpty()) {
                $sedes = $fuera->map(fn ($c) => $c->sede?->nombre ?? 'sin sede asignada')
                    ->unique()->implode(', ');

                return [
                    'exito' => false,
                    'falta' => "El cliente \"{$fuera->first()->nombreCompleto()}\" SÍ existe, pero no "
                        . "está en la sede activa (aparece en: {$sedes}), y las cotizaciones solo "
                        . 'pueden hacerse con clientes de la sede activa. Para cotizarlo: cambia de '
                        . 'sede en el encabezado, o asígnalo a esta sede desde el módulo de Clientes.',
                ];
            }

            return [
                'exito' => false,
                'falta' => "No encontré ningún cliente que coincida con \"{$nombreCliente}\" en esta sede "
                    . '(busqué por nombre, apellido y número de identificación). '
                    . 'Verifica el nombre o créalo primero en Clientes.',
            ];
        }

        if ($candidatos->count() > 1) {
            return [
                'exito' => false,
                'falta' => 'Hay varios clientes que coinciden. ¿Cuál de estos es?',
                'opciones' => $candidatos->map(fn ($c) => $c->nombreCompleto())->all(),
            ];
        }

        $cliente = $candidatos->first();

        if (! $cliente->activo) {
            return [
                'exito' => false,
                'falta' => "El cliente \"{$cliente->nombreCompleto()}\" está INACTIVO. "
                    . 'Actívalo en el módulo de Clientes antes de cotizarle.',
            ];
        }

        // ── Productos: se buscan en el catálogo ───────────────────────────────
        $encontrados = [];
        $noEncontrados = [];

        foreach ($items as $item) {
            $busca    = trim((string) ($item['producto'] ?? ''));
            $cantidad = (float) ($item['cantidad'] ?? 1);

            if ($busca === '') {
                continue;
            }

            $producto = Producto::where('activo', true)
                ->where(fn ($q) => $q->where('nombre', 'like', "%{$busca}%")
                                     ->orWhere('referencia', 'like', "%{$busca}%"))
                ->first();

            if (! $producto) {
                $noEncontrados[] = $busca;
                continue;
            }

            $encontrados[] = [
                'producto' => $producto,
                'cantidad' => $cantidad > 0 ? $cantidad : 1,
                'buscado'  => $busca,
            ];
        }

        if (! empty($noEncontrados)) {
            return [
                'exito' => false,
                'falta' => 'No encontré estos productos en el catálogo: ' . implode(', ', $noEncontrados)
                    . '. Dime el nombre o la referencia exacta.',
            ];
        }

        if (empty($encontrados)) {
            return ['exito' => false, 'falta' => 'No pude identificar ningún producto.'];
        }

        // ── Se crea, siempre en borrador ──────────────────────────────────────
        $cotizacion = DB::transaction(function () use ($cliente, $encontrados, $p) {
            $cot = Cotizacion::create([
                'cliente_id'     => $cliente->id,
                'responsable_id' => auth()->id(),
                'estado'         => 'borrador',
                'moneda'         => 'COP',
                'notas_internas' => trim('Creada por el asistente. ' . ($p['notas'] ?? '')),
            ]);

            foreach ($encontrados as $i => $linea) {
                /** @var Producto $producto */
                $producto = $linea['producto'];

                // EL PRECIO SALE DEL CATÁLOGO, no de la IA.
                $precio    = (float) ($producto->precio_cliente_final ?: 0);
                $mayorista = (float) ($producto->precio_mayorista ?: 0);
                $subtotal  = $precio * $linea['cantidad'];

                CotizacionItem::create([
                    'cotizacion_id'         => $cot->id,
                    'tipo'                  => 'producto',
                    'producto_id'           => $producto->id,
                    'orden'                 => $i,
                    'descripcion'           => $producto->nombre,
                    'cantidad'              => $linea['cantidad'],
                    'precio_unitario'       => $precio,
                    'precio_mayorista_base' => $mayorista,
                    'descuento_pct'         => 0,
                    'subtotal'              => $subtotal,
                    'impuesto_pct'          => 0,
                    'impuesto_valor'        => 0,
                    'total_linea'           => $subtotal,
                    'comision_pct_aplicada' => 0,
                    'comision_valor'        => 0,
                ]);
            }

            $cot->load('items');
            $cot->recalcularTotales();

            return $cot;
        });

        // Aviso si algún producto no tenía precio cargado: es un problema real
        // que el usuario debe ver antes de enviar la cotización.
        $sinPrecio = collect($encontrados)
            ->filter(fn ($l) => ! (float) $l['producto']->precio_cliente_final)
            ->map(fn ($l) => $l['producto']->nombre)
            ->values()
            ->all();

        return [
            'exito'      => true,
            'numero'     => $cotizacion->numero,
            'cliente'    => $cliente->nombreCompleto(),
            'estado'     => 'borrador',
            'url'        => "/cotizaciones/{$cotizacion->id}",
            'total'      => round((float) $cotizacion->total),
            'moneda'     => 'COP',
            'items'      => collect($encontrados)->map(fn ($l) => [
                'producto' => $l['producto']->nombre,
                'cantidad' => $l['cantidad'],
                'precio'   => round((float) $l['producto']->precio_cliente_final),
            ])->all(),
            'sin_precio' => $sinPrecio,
            'aviso'      => 'Quedó en BORRADOR. Debe revisarse y confirmarse antes de enviarla al cliente.',
        ];
    }
}
