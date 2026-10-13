<?php

namespace App\Services\IA;

use App\Models\Producto;
use Illuminate\Support\Facades\DB;

/**
 * Lo que la guía puede hacer con el catálogo de productos: por ahora, renombrarlos en lote.
 *
 * El caso que lo pidió: al cargar productos, varias variantes quedaron con el mismo nombre
 * («Tirador pasante palanca» tres veces) y solo se distinguían por el código. La guía los busca
 * con la consulta `productos_nombre_repetido`, propone un nombre para cada uno y, al confirmar,
 * los cambia todos juntos.
 *
 * Mismas reglas que todas las transacciones (ver `TransaccionesIaService`): **proponer no escribe**,
 * confirmar vuelve a revisar y exige que salga lo mismo, y pide el permiso de editar productos.
 * Solo cambia el nombre: lo hace con `update()` del modelo, que es lo mismo que guarda la pantalla
 * para ese campo, así que queda en la auditoría y dispara los mismos observadores.
 *
 * No deja crear un nombre repetido nuevo: el objetivo es diferenciarlos, y un cambio que vuelve a
 * chocar con otro producto se devuelve a la guía para que proponga algo distinto.
 */
trait TransaccionesProductos
{
    /** Cuántos productos se renombran de una vez: lo que cabe revisar en una tarjeta. */
    private const MAX_RENOMBRAR = 100;

    public static function catalogoProductos(): array
    {
        return [
            'renombrar_productos' => [
                'descripcion' => 'Cambia el NOMBRE de uno o varios productos del catálogo, identificados por su referencia (código). '
                    . 'Úsala para diferenciar productos que quedaron con el mismo nombre: antes, consulta productos_nombre_repetido '
                    . 'para ver sus referencias y descripciones, y arma un nombre distinto para cada uno con lo que los diferencia '
                    . '(tipo, medida, color, material). Si con lo que hay no se ve qué los diferencia, PREGUNTA en vez de inventar. '
                    . 'Solo cambia el nombre; lo demás del producto queda igual.',
                'parametros'  => [
                    'cambios' => 'lista de {referencia: "código del producto", nombre: "nombre nuevo"} (obligatorio; hasta ' . self::MAX_RENOMBRAR . '). Si un producto no tiene referencia, usa {id: número} con el id que dio la consulta.',
                ],
                'permiso'     => 'productos.editar',
            ],
        ];
    }

    private function prepararRenombrarProductos(array $p): array
    {
        $cambios = $p['cambios'] ?? null;
        if (is_string($cambios)) {
            $cambios = json_decode($cambios, true);
        }
        if (! is_array($cambios) || $cambios === []) {
            throw new FaltaDato('¿Qué productos cambio y con qué nombre? Dime la referencia y el nombre nuevo de cada uno.');
        }
        if (count($cambios) > self::MAX_RENOMBRAR) {
            throw new FaltaDato('Son ' . count($cambios) . ' productos: los cambio de a ' . self::MAX_RENOMBRAR . ' como máximo. Partamos la lista.');
        }

        $plan = [];
        $noEncontrados = [];

        foreach ($cambios as $cambio) {
            $referencia = trim((string) ($cambio['referencia'] ?? ''));
            $id         = (int) ($cambio['id'] ?? 0);
            $nombre     = trim(preg_replace('/\s+/', ' ', (string) ($cambio['nombre'] ?? '')));

            $producto = $referencia !== ''
                ? Producto::where('referencia', $referencia)->first()
                : ($id ? Producto::find($id) : null);

            if (! $producto) {
                $noEncontrados[] = $referencia !== '' ? $referencia : ($id ? "id {$id}" : '(sin referencia)');
                continue;
            }
            if ($nombre === '') {
                throw new FaltaDato("Falta el nombre nuevo de «{$producto->nombre}» ({$this->codigoDe($producto)}).");
            }
            if (mb_strlen($nombre) > 200) {
                throw new FaltaDato("El nombre para {$this->codigoDe($producto)} pasa de 200 caracteres: acórtalo.");
            }
            if (isset($plan[$producto->id])) {
                throw new FaltaDato("El producto {$this->codigoDe($producto)} viene dos veces en la lista.");
            }
            if ($nombre === $producto->nombre) {
                continue;
            }

            $plan[$producto->id] = ['id' => $producto->id, 'antes' => $producto->nombre, 'nombre' => $nombre, 'codigo' => $this->codigoDe($producto)];
        }

        if ($noEncontrados) {
            throw new FaltaDato('No encontré productos con estas referencias: ' . implode(', ', $noEncontrados) . '. Revisa los códigos.');
        }
        if ($plan === []) {
            throw new FaltaDato('Esos productos ya se llaman así: no hay nada que cambiar.');
        }

        $this->revisarQueNoChoquen($plan);

        $plan = array_values($plan);
        $lineas = collect($plan)->map(fn ($c) => "• {$c['codigo']}: «{$c['antes']}» → «{$c['nombre']}»")->implode("\n");

        return [
            // Lo que se compara al confirmar: si alguien renombró uno entre tanto, se pide de nuevo.
            collect($plan)->map(fn ($c) => ['id' => $c['id'], 'antes' => $c['antes'], 'nombre' => $c['nombre']])->all(),
            'Renombrar ' . count($plan) . ' ' . (count($plan) === 1 ? 'producto' : 'productos') . ":\n{$lineas}",
        ];
    }

    private function hacerRenombrarProductos(array $p): array
    {
        DB::transaction(function () use ($p) {
            foreach ($p as $c) {
                Producto::findOrFail($c['id'])->update(['nombre' => $c['nombre']]);
            }
        });

        $n = count($p);

        return ['exito' => true, 'mensaje' => "Listo: {$n} " . ($n === 1 ? 'producto renombrado' : 'productos renombrados') . '.', 'url' => '/productos'];
    }

    /**
     * Ningún nombre nuevo puede repetirse dentro de la lista ni con otro producto activo que se
     * quede como está. Se compara sin mayúsculas ni espacios de más, como lo vería una persona.
     */
    private function revisarQueNoChoquen(array $plan): void
    {
        $clave = fn (string $n) => mb_strtolower(trim(preg_replace('/\s+/u', ' ', $n)));

        $nuevos = [];
        foreach ($plan as $c) {
            $k = $clave($c['nombre']);
            if (isset($nuevos[$k])) {
                throw new FaltaDato("«{$c['nombre']}» quedaría igual para {$nuevos[$k]} y {$c['codigo']}: dales nombres distintos.");
            }
            $nuevos[$k] = $c['codigo'];
        }

        // En PHP, por la misma razón que la consulta: los espacios de más se juntan sin REGEXP_REPLACE.
        $choques = Producto::where('activo', true)
            ->whereNotIn('id', array_keys($plan))
            ->get(['id', 'nombre', 'referencia'])
            ->filter(fn (Producto $p) => isset($nuevos[$clave($p->nombre)]));

        if ($choques->isNotEmpty()) {
            $lista = $choques->map(fn ($p) => "«{$p->nombre}» ({$this->codigoDe($p)})")->implode(', ');
            throw new FaltaDato("Ya existe otro producto con ese nombre: {$lista}. Propón un nombre que lo diferencie, o inclúyelo también en la lista.");
        }
    }

    private function codigoDe(Producto $producto): string
    {
        return $producto->referencia ?: "id {$producto->id}";
    }
}
