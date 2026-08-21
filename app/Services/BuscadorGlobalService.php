<?php

namespace App\Services;

use App\Models\Cliente;
use App\Models\Cotizacion;
use App\Models\CrmLead;
use App\Models\Op;
use App\Models\OpItem;
use App\Models\OrdenCompra;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\Remision;
use App\Models\SolicitudCompra;
use App\Models\User;
use App\Support\ContextoSede;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

/**
 * Buscador global: un solo campo para encontrar cualquier cosa del sistema.
 *
 * Dos reglas que no se negocian, porque de ellas depende que se pueda confiar
 * en el resultado:
 *
 *   1. Solo aparece lo que el usuario tiene permiso de ver. El buscador no es
 *      una puerta trasera a módulos que su rol no le permite abrir.
 *   2. Solo aparece lo de la sede activa, igual que en los listados. Si el
 *      encabezado dice Bogotá, no salen OPs de Cali.
 *
 * Antes había seis buscadores sueltos (productos, ensambles, OPs, inventario,
 * clientes para cotizar). Este los unifica sin quitarlos: los otros siguen
 * sirviendo para sus selectores dentro de formularios.
 */
class BuscadorGlobalService
{
    /**
     * Cuántos resultados por tipo. Suficiente para reconocer, sin abrumar.
     *
     * Es propiedad y no constante porque el buscador de un módulo pide más: ahí el usuario ya
     * sabe qué está buscando y no hay otros tipos compitiendo por el espacio de la lista.
     */
    private int $porTipo = 5;

    /**
     * @param  array<int, string>  $tipos  Limita la búsqueda a estas fuentes. Vacío = todas.
     * @param  int|null  $limite  Cuántos resultados por tipo. Null deja el de siempre.
     * @return array<int, array{tipo:string, etiqueta:string, color:string, resultados:array}>
     */
    public function buscar(string $termino, array $tipos = [], ?int $limite = null): array
    {
        $termino = trim($termino);

        // Con menos de dos letras cualquier búsqueda devuelve medio sistema.
        if (mb_strlen($termino) < 2) {
            return [];
        }

        if ($limite !== null) {
            // Con tope, para que un `limite` grande escrito en la URL no vuelva la sugerencia
            // una consulta pesada por cada tecla.
            $this->porTipo = max(1, min($limite, 20));
        }

        $grupos = [];

        foreach ($this->fuentes() as $fuente) {
            // El filtro por tipo es del lado del servidor, no de la pantalla: pedir un solo
            // módulo tiene que ahorrar las once consultas de los demás, no solo esconderlas.
            if ($tipos && ! in_array($fuente['tipo'], $tipos, true)) {
                continue;
            }

            if (! auth()->user()?->tienePermiso($fuente['permiso'])) {
                continue;
            }

            // Cada fuente va aislada.
            //
            // Sin esto, un solo error en una consulta —una columna mal
            // escrita, por ejemplo— tumbaba el buscador entero y el usuario
            // veía "no encontré nada" aunque el cliente existiera. Ahora esa
            // fuente se salta, queda en el log con nombre propio, y las demás
            // siguen respondiendo.
            try {
                $resultados = ($fuente['buscar'])($termino);
            } catch (\Throwable $e) {
                Log::error("Buscador: falló la fuente '{$fuente['tipo']}': {$e->getMessage()}");

                continue;
            }

            if (! empty($resultados)) {
                $grupos[] = [
                    'tipo'       => $fuente['tipo'],
                    'etiqueta'   => $fuente['etiqueta'],
                    'color'      => $fuente['color'],
                    'resultados' => $resultados,
                ];
            }
        }

        return $grupos;
    }

    /**
     * Aplica el filtro de sede solo si la tabla la maneja. Productos y
     * proveedores son catálogo compartido: no tienen sede.
     */
    private function porSede(Builder $q): Builder
    {
        return ContextoSede::aplicar($q);
    }

    /** Búsqueda por varias columnas a la vez. */
    private function comoEn(Builder $q, array $columnas, string $termino): Builder
    {
        return $q->where(function ($sub) use ($columnas, $termino) {
            foreach ($columnas as $col) {
                $sub->orWhere($col, 'like', "%{$termino}%");
            }
        });
    }

    /**
     * Catálogo de lo que se puede encontrar.
     *
     * Cada fuente sabe su permiso, cómo buscarse y cómo describirse. Agregar
     * un módulo nuevo al buscador es agregar una entrada aquí.
     */
    private function fuentes(): array
    {
        return [
            [
                'tipo' => 'cliente', 'etiqueta' => 'Clientes', 'color' => 'azul',
                'permiso' => 'clientes.ver',
                'buscar' => fn ($t) => $this->porSede(Cliente::query())
                    ->where(fn ($q) => $this->comoEn($q, ['nombre', 'apellido', 'numero_identificacion', 'email', 'celular', 'telefono'], $t))
                    ->limit($this->porTipo)->get()
                    ->map(fn (Cliente $c) => [
                        'titulo'   => $c->nombreCompleto(),
                        'detalle'  => trim($c->identificacionCompleta() . ($c->ciudad ? " · {$c->ciudad}" : '')),
                        'url'      => "/clientes/{$c->id}",
                    ])->all(),
            ],
            [
                'tipo' => 'op', 'etiqueta' => 'Órdenes de producción', 'color' => 'indigo',
                'permiso' => 'ops.ver',
                'buscar' => fn ($t) => $this->porSede(Op::with('cliente:id,nombre,apellido'))
                    ->where(fn ($q) => $q->where('numero', 'like', "%{$t}%")
                        ->orWhereHas('cliente', fn ($c) => $this->comoEn($c, ['nombre', 'apellido', 'numero_identificacion'], $t)))
                    ->latest()->limit($this->porTipo)->get()
                    ->map(fn (Op $o) => [
                        'titulo'  => $o->numero,
                        'detalle' => trim(($o->cliente?->nombre ?? 'Sin cliente') . ' · ' . str_replace('_', ' ', $o->estado)),
                        'url'     => "/produccion/ops/{$o->id}",
                    ])->all(),
            ],
            [
                'tipo' => 'cotizacion', 'etiqueta' => 'Cotizaciones', 'color' => 'morado',
                'permiso' => 'cotizaciones.ver',
                'buscar' => fn ($t) => $this->porSede(Cotizacion::with('cliente:id,nombre,apellido'))
                    ->where(fn ($q) => $q->where('numero', 'like', "%{$t}%")
                        ->orWhereHas('cliente', fn ($c) => $this->comoEn($c, ['nombre', 'apellido', 'numero_identificacion'], $t)))
                    ->latest()->limit($this->porTipo)->get()
                    ->map(fn (Cotizacion $c) => [
                        'titulo'  => $c->numero,
                        'detalle' => trim(($c->cliente?->nombre ?? 'Sin cliente') . ' · ' . str_replace('_', ' ', $c->estado)),
                        'url'     => "/cotizaciones/{$c->id}",
                    ])->all(),
            ],
            [
                'tipo' => 'remision', 'etiqueta' => 'Remisiones', 'color' => 'verde',
                'permiso' => 'remisiones.ver',
                'buscar' => fn ($t) => $this->porSede(Remision::with('cliente:id,nombre,apellido'))
                    ->where(fn ($q) => $this->comoEn($q, ['numero', 'transportista', 'placa'], $t)
                        ->orWhereHas('cliente', fn ($c) => $this->comoEn($c, ['nombre', 'apellido'], $t)))
                    ->latest()->limit($this->porTipo)->get()
                    ->map(fn (Remision $r) => [
                        'titulo'  => $r->numero,
                        'detalle' => trim(($r->cliente?->nombre ?? '') . ' · ' . str_replace('_', ' ', $r->estado)),
                        'url'     => "/logistica/remisiones/{$r->id}",
                    ])->all(),
            ],
            [
                // Un número de serie lleva a la OP donde se fabricó esa pieza.
                'tipo' => 'serie', 'etiqueta' => 'Números de serie', 'color' => 'indigo',
                'permiso' => 'ops.ver',
                'buscar' => fn ($t) => OpItem::with('op:id,numero,sede_id')
                    ->whereNotNull('numero_serie')
                    ->where('numero_serie', 'like', "%{$t}%")
                    ->whereHas('op', fn ($q) => $this->porSede($q))
                    ->limit($this->porTipo)->get()
                    ->map(fn (OpItem $i) => [
                        'titulo'  => $i->numero_serie,
                        'detalle' => trim(($i->descripcion ?? '') . ' · ' . ($i->op?->numero ?? '')),
                        'url'     => $i->op ? "/produccion/ops/{$i->op->id}" : '#',
                    ])->all(),
            ],
            [
                'tipo' => 'producto', 'etiqueta' => 'Productos', 'color' => 'ambar',
                'permiso' => 'productos.ver',
                // Catálogo compartido: no se filtra por sede.
                'buscar' => fn ($t) => Producto::query()
                    ->where(fn ($q) => $this->comoEn($q, ['nombre', 'referencia', 'descripcion_corta'], $t))
                    ->limit($this->porTipo)->get()
                    ->map(fn (Producto $p) => [
                        'titulo'  => $p->nombre,
                        'detalle' => trim(($p->referencia ?? '') . ' · ' . $p->tipo),
                        'url'     => "/productos/{$p->id}",
                    ])->all(),
            ],
            [
                'tipo' => 'proveedor', 'etiqueta' => 'Proveedores', 'color' => 'gris',
                'permiso' => 'proveedores.ver',
                'buscar' => fn ($t) => Proveedor::query()
                    ->where(fn ($q) => $this->comoEn($q, ['nombre', 'nit', 'contacto', 'email', 'telefono'], $t))
                    ->limit($this->porTipo)->get()
                    ->map(fn (Proveedor $p) => [
                        'titulo'  => $p->nombre,
                        'detalle' => trim(($p->nit ?? '') . ($p->ciudad ? " · {$p->ciudad}" : '')),
                        'url'     => "/compras/proveedores/{$p->id}",
                    ])->all(),
            ],
            [
                'tipo' => 'orden_compra', 'etiqueta' => 'Órdenes de compra', 'color' => 'gris',
                'permiso' => 'ordenes.ver',
                'buscar' => fn ($t) => $this->porSede(OrdenCompra::with('proveedor:id,nombre'))
                    ->where(fn ($q) => $q->where('numero', 'like', "%{$t}%")
                        ->orWhereHas('proveedor', fn ($p) => $p->where('nombre', 'like', "%{$t}%")))
                    ->latest()->limit($this->porTipo)->get()
                    ->map(fn (OrdenCompra $o) => [
                        'titulo'  => $o->numero,
                        'detalle' => trim(($o->proveedor?->nombre ?? '') . ' · ' . str_replace('_', ' ', $o->estado)),
                        'url'     => "/compras/ordenes/{$o->id}",
                    ])->all(),
            ],
            [
                'tipo' => 'solicitud', 'etiqueta' => 'Solicitudes de compra', 'color' => 'gris',
                'permiso' => 'solicitudes.ver',
                'buscar' => fn ($t) => $this->porSede(SolicitudCompra::query())
                    ->where(fn ($q) => $this->comoEn($q, ['numero', 'motivo'], $t))
                    ->latest()->limit($this->porTipo)->get()
                    ->map(fn (SolicitudCompra $s) => [
                        'titulo'  => $s->numero,
                        'detalle' => str_replace('_', ' ', $s->estado),
                        'url'     => "/compras/solicitudes/{$s->id}",
                    ])->all(),
            ],
            [
                'tipo' => 'lead', 'etiqueta' => 'Leads del CRM', 'color' => 'morado',
                'permiso' => 'crm.ver',
                'buscar' => fn ($t) => $this->porSede(CrmLead::query())
                    ->where(fn ($q) => $this->comoEn($q, ['titulo', 'nombre_contacto', 'empresa_contacto', 'email_contacto', 'telefono_contacto'], $t))
                    ->latest()->limit($this->porTipo)->get()
                    ->map(fn (CrmLead $l) => [
                        'titulo'  => $l->titulo,
                        'detalle' => trim(($l->empresa_contacto ?? $l->nombre_contacto ?? '')),
                        'url'     => "/crm/leads/{$l->id}",
                    ])->all(),
            ],
            [
                'tipo' => 'usuario', 'etiqueta' => 'Usuarios', 'color' => 'gris',
                'permiso' => 'usuarios.ver',
                'buscar' => fn ($t) => User::query()
                    ->where(fn ($q) => $this->comoEn($q, ['name', 'email'], $t))
                    ->limit($this->porTipo)->get()
                    ->map(fn (User $u) => [
                        'titulo'  => $u->name,
                        'detalle' => $u->email,
                        'url'     => "/usuarios/{$u->id}/edit",
                    ])->all(),
            ],
        ];
    }
}
