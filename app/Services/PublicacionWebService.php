<?php

namespace App\Services;

use App\Models\Configuracion;
use App\Models\Ensamble;
use App\Models\Producto;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Qué del catálogo sale al sitio web del cliente, y cómo se lo entera el sitio.
 *
 * El ERP es la fuente de la verdad del precio y de las existencias; el sitio es una
 * vitrina. Por eso la marca «publicado» vive aquí y no en WordPress: el plugin
 * Briela Connect pregunta qué hay publicado y crea o actualiza las fichas.
 *
 * **La dirección importa.** Es el sitio el que llama al ERP, nunca al revés como regla:
 * así el ERP no necesita guardar credenciales de WordPress, y funciona igual si el sitio
 * está detrás de Cloudflare o si el hosting del cliente no deja salir peticiones
 * (regla 5 del producto instalable). El aviso inmediato de `avisarAlSitio()` es un lujo
 * que se intenta y que puede fallar sin consecuencias: la sincronización periódica del
 * plugin recoge lo mismo un rato después.
 */
class PublicacionWebService
{
    /** Dónde vive el sitio del cliente. Lo aprende solo, ver `recordarSitio()`. */
    private const CLAVE_SITIO = 'integracion_wordpress_sitio_url';

    /** Cuándo fue la última vez que el plugin pidió el catálogo. */
    private const CLAVE_ULTIMA_LECTURA = 'integracion_wordpress_ultima_lectura';

    public function __construct(
        private CanalesPrecioService $canales,
        private PreciosPorCanalService $precios,
    ) {}

    /**
     * Marca o desmarca un producto o un ensamble.
     *
     * Devuelve el motivo por el que no se pudo, o null si quedó hecho. Se decide aquí y
     * no en el controlador porque la regla es la misma para uno y para cien.
     */
    public function marcar(Model $item, bool $publicar): ?string
    {
        if ($publicar && $motivo = $this->motivoParaNoPublicar($item)) {
            return $motivo;
        }

        $item->publicado_web = $publicar;

        // La fecha solo avanza al publicar. Al despublicar se conserva: dice cuándo
        // estuvo en la web por última vez, que es lo que uno quiere saber después.
        if ($publicar) {
            $item->publicado_web_at = now();
        }

        $item->save();

        return null;
    }

    /**
     * Lo que impide publicar algo, dicho de una manera que se pueda arreglar.
     *
     * Un insumo en la vitrina es una promesa que la empresa no piensa cumplir: alguien va
     * a pedir un tornillo suelto porque estaba publicado. Y sin precio público, la ficha
     * sale sin cifra y no hay forma de saber si eso fue a propósito.
     */
    public function motivoParaNoPublicar(Model $item): ?string
    {
        if ($item instanceof Producto) {
            if ($item->es_padre === false && ! $item->es_vendible) {
                return "«{$item->nombre}» no está marcado como vendible, y en la web se vende. "
                    . 'Márcalo como vendible en su ficha y vuelve a intentarlo.';
            }

            if ($item->producto_padre_id) {
                return "«{$item->nombre}» es una variante. Publica el producto padre y sus "
                    . 'variantes salen con él.';
            }
        }

        if (! $this->canales->publico()) {
            return 'Todavía ningún canal está marcado como precio público en Segmentación, '
                . 'así que la web no sabría qué precio mostrar. Marca uno y vuelve a intentarlo.';
        }

        return null;
    }

    /**
     * El catálogo tal como lo consume el plugin.
     *
     * Un ensamble viaja como un producto más —en WordPress no existe la diferencia— pero
     * con `precio_es_desde` en verdadero: su precio final depende de las medidas, y
     * publicarlo como precio cerrado es prometer una cifra que no se va a cumplir.
     *
     * @return array<int, array<string, mixed>>
     */
    public function catalogo(): array
    {
        $productos = Producto::with(['categoria', 'imagenes', 'variantes.stocks'])
            ->where('publicado_web', true)
            ->whereNull('producto_padre_id')
            ->orderBy('nombre')
            ->get()
            ->map(fn (Producto $p) => $this->productoComoFicha($p));

        $ensambles = Ensamble::with('categoria')
            ->where('publicado_web', true)
            ->orderBy('nombre')
            ->get()
            ->map(fn (Ensamble $e) => $this->ensambleComoFicha($e));

        return $productos->concat($ensambles)->values()->all();
    }

    /** @return array<string, mixed> */
    private function productoComoFicha(Producto $producto): array
    {
        $imagenes = $producto->imagenes
            ->sortByDesc('es_principal')
            ->map(fn ($img) => asset('storage/'.$img->ruta))
            ->values()->all();

        return [
            'tipo'              => 'producto',
            'id'                => $producto->id,
            'nombre'            => $producto->nombre,
            'referencia'        => $producto->referencia,
            'categoria'         => $producto->categoria?->nombre,
            'unidad'            => $producto->unidad_medida,
            'descripcion_corta' => $producto->descripcion_corta,
            'descripcion_larga' => $producto->descripcion_larga,
            'precio'            => $this->precioParaWeb($producto),
            'precio_es_desde'   => false,
            'gestiona_stock'    => (bool) $producto->inventariable,
            'stock'             => $producto->inventariable ? (float) $producto->stockTotal() : null,
            'imagenes'          => $imagenes,
            'url_ficha'         => url("/catalogo/productos/{$producto->id}"),
            // Las variantes son las que de verdad se venden («lámina 40 mm», no «lámina»),
            // así que viajan como fichas hijas y el sitio decide cómo mostrarlas.
            'variantes'         => $producto->variantes->map(fn ($v) => [
                'id'         => $v->id,
                'nombre'     => $v->nombre_completo ?? $v->nombre,
                'valor'      => $v->valor_variante,
                'referencia' => $v->referencia,
                'precio'     => $this->precioParaWeb($v),
                'stock'      => $v->inventariable ? (float) $v->stocks->sum('cantidad') : null,
            ])->values()->all(),
            'actualizado_at'    => $producto->updated_at?->toIso8601String(),
            'publicado_web_at'  => $producto->publicado_web_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function ensambleComoFicha(Ensamble $ensamble): array
    {
        $imagenes = collect();

        if ($ensamble->imagen_principal) {
            $imagenes->push(asset('storage/'.$ensamble->imagen_principal));
        }

        foreach ((array) $ensamble->imagenes_secundarias as $ruta) {
            if (is_string($ruta) && $ruta !== '') {
                $imagenes->push(asset('storage/'.$ruta));
            }
        }

        return [
            'tipo'              => 'ensamble',
            'id'                => $ensamble->id,
            'nombre'            => $ensamble->nombre,
            'referencia'        => 'ENS-'.$ensamble->id,
            'categoria'         => $ensamble->categoria?->nombre,
            'unidad'            => 'unidad',
            'descripcion_corta' => $ensamble->descripcion_corta,
            'descripcion_larga' => $ensamble->descripcion_larga,
            'precio'            => $this->precioParaWeb($ensamble),
            // Lo que hace distinto a un ensamble en la vitrina: el precio es un punto de
            // partida, el de la configuración base, y el final sale de las medidas.
            'precio_es_desde'   => true,
            'gestiona_stock'    => false,
            'stock'             => null,
            'imagenes'          => $imagenes->unique()->values()->all(),
            'url_ficha'         => url("/catalogo/ensambles/{$ensamble->id}"),
            'variantes'         => [],
            'actualizado_at'    => $ensamble->updated_at?->toIso8601String(),
            'publicado_web_at'  => $ensamble->publicado_web_at?->toIso8601String(),
        ];
    }

    /**
     * El precio que ve un desconocido: el del canal marcado como precio público.
     *
     * Mismo criterio que el catálogo público del ERP: si ningún canal está marcado, no hay
     * precio. Antes que mostrar uno cualquiera —que podría ser el mayorista— la ficha sale
     * sin cifra. El respaldo a la columna vieja lo resuelve `PreciosPorCanalService`, que
     * es donde vive el período de compatibilidad.
     *
     * **Cero no es un precio.** Un ítem sin precio cargado devuelve 0, y publicar «$0» en
     * la web es peor que no publicar cifra: parece un regalo o un error del sitio. Se manda
     * en nulo y el sitio muestra su botón de cotizar.
     */
    public function precioParaWeb(Model $item): ?float
    {
        $precio = $this->precios->precioDe($item, $this->canales->publico());

        return $precio !== null && $precio > 0 ? $precio : null;
    }


    /**
     * Le pide al sitio que sincronice ya.
     *
     * Puede fallar por diez razones que no son culpa de nadie —el hosting del cliente no
     * deja salir peticiones, el sitio está detrás de un WAF, el plugin no está instalado
     * todavía— y ninguna es grave: el plugin sincroniza solo cada hora. Por eso esto
     * nunca lanza una excepción; devuelve si se pudo, para poder decirlo en pantalla.
     *
     * @return array{avisado: bool, mensaje: string}
     */
    public function avisarAlSitio(): array
    {
        $sitio = $this->sitio();
        $token = Configuracion::get('integracion_wordpress_token', '');

        if ($sitio === '' || $token === '') {
            return [
                'avisado' => false,
                'mensaje' => 'El sitio se enterará en su próxima sincronización.',
            ];
        }

        try {
            $respuesta = Http::withToken($token)
                ->timeout(5)
                ->acceptJson()
                ->post(rtrim($sitio, '/').'/wp-json/briela/v1/sincronizar');

            if ($respuesta->successful()) {
                return ['avisado' => true, 'mensaje' => 'El sitio ya quedó actualizado.'];
            }

            $motivo = 'el sitio respondió '.$respuesta->status();
        } catch (\Throwable $e) {
            $motivo = $e->getMessage();
            Log::info('No se pudo avisar al sitio web: '.$motivo);
        }

        return [
            'avisado' => false,
            'mensaje' => 'No se pudo avisar al sitio ahora mismo, así que se actualizará en '
                .'su próxima sincronización.',
        ];
    }

    public function sitio(): string
    {
        return (string) Configuracion::get(self::CLAVE_SITIO, '');
    }

    /**
     * Guarda de dónde viene el plugin, la primera vez que pide el catálogo.
     *
     * Así nadie tiene que escribir la URL del sitio dos veces: el plugin ya conoce la del
     * ERP porque se la pegaron al instalarlo, y de vuelta se identifica. Si el cliente
     * cambia de dominio, la próxima lectura corrige el dato sola.
     */
    public function recordarSitio(?string $url): void
    {
        $url = trim((string) $url);

        if ($url === '' || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return;
        }

        if ($url !== $this->sitio()) {
            Configuracion::set(self::CLAVE_SITIO, rtrim($url, '/'));
        }
    }

    public function registrarLectura(): void
    {
        Configuracion::set(self::CLAVE_ULTIMA_LECTURA, now()->toDateTimeString());
    }

    public function ultimaLectura(): ?string
    {
        $valor = Configuracion::get(self::CLAVE_ULTIMA_LECTURA, '');

        return $valor !== '' ? $valor : null;
    }

    /** Cuántos hay publicados de cada cosa, para la pantalla de la integración. */
    public function conteo(): array
    {
        return [
            'productos' => Producto::where('publicado_web', true)->whereNull('producto_padre_id')->count(),
            'ensambles' => Ensamble::where('publicado_web', true)->count(),
        ];
    }
}
