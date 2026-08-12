<?php

namespace App\Services;

use App\Models\Cliente;
use App\Models\SegmentacionOpcion;
use Illuminate\Support\Collection;

/**
 * Quién paga qué precio.
 *
 * Los canales de precio son tipos de contacto marcados en Segmentación, y este servicio
 * es el único lugar que traduce «este cliente» a «este canal». Antes esa traducción
 * estaba escrita dentro de `Cotizaciones/Create.vue`, comparando textos a mano, y por eso
 * no se podía cambiar sin tocar código.
 *
 * Tres papeles, cada uno con su marca en la interfaz:
 *
 * - **canal base**: el piso de utilidad de la empresa. No paga comisión, y la comisión de
 *   los demás canales se calcula contra su precio.
 * - **precio público**: el que se muestra en el catálogo a quien no ha entrado.
 * - **el resto**: canales normales.
 */
class CanalesPrecioService
{
    /** Los canales de precio, en orden. El orden es la prioridad. */
    public function canales(): Collection
    {
        return SegmentacionOpcion::canalesDePrecio()->get();
    }

    /**
     * El canal base. Null si nadie lo marcó.
     *
     * Devolver null y no adivinar es a propósito: si el canal base no existe, las
     * comisiones no se pueden calcular, y es mejor que la pantalla lo diga que inventar
     * un piso de utilidad.
     */
    public function base(): ?SegmentacionOpcion
    {
        return SegmentacionOpcion::canalesDePrecio()->where('es_canal_base', true)->first();
    }

    public function publico(): ?SegmentacionOpcion
    {
        return SegmentacionOpcion::canalesDePrecio()->where('es_precio_publico', true)->first();
    }

    /**
     * El canal que le corresponde a un cliente. Null si no le corresponde ninguno.
     *
     * Un cliente puede tener varios tipos de contacto a la vez —mayorista y distribuidor,
     * por ejemplo—. Gana el que esté más arriba en la lista de Segmentación, que es un
     * orden que la empresa controla arrastrando las opciones. Antes ganaba `mayorista`
     * porque estaba primero en un `if` del código.
     *
     * Null significa «este cliente no está segmentado»: no se le muestran precios y se
     * le pide segmentarlo. Es deliberado — mostrarle un precio por omisión es la forma
     * de vender al precio equivocado sin que nadie lo note.
     */
    public function paraCliente(?Cliente $cliente): ?SegmentacionOpcion
    {
        if (! $cliente) {
            return null;
        }

        $suyos = collect($cliente->tipos_contacto ?? [])->filter()->all();

        if ($suyos === []) {
            return null;
        }

        return $this->canales()->firstWhere(fn ($canal) => in_array($canal->valor, $suyos, true));
    }

    /**
     * Por qué un cliente no tiene canal, en palabras que sirvan en pantalla.
     *
     * Distingue los dos casos que se sienten igual y se arreglan distinto: el cliente sin
     * segmentar, y el cliente segmentado con tipos que no definen precio —«Prospecto», por
     * ejemplo—. Sin esa distinción, alguien va a revisar el cliente, verá que sí tiene
     * tipo de contacto, y no va a entender nada.
     */
    public function motivoSinCanal(?Cliente $cliente): ?string
    {
        if ($this->paraCliente($cliente) !== null) {
            return null;
        }

        if (! $cliente) {
            return 'Elige un cliente para ver los precios que le corresponden.';
        }

        $suyos = collect($cliente->tipos_contacto ?? [])->filter();

        if ($suyos->isEmpty()) {
            return 'Este cliente no está segmentado, así que no se le pueden calcular precios. '
                . 'Asígnale un tipo de contacto en su ficha.';
        }

        $etiquetas = SegmentacionOpcion::porTipo('tipo_contacto')
            ->whereIn('valor', $suyos->all())->pluck('etiqueta')->implode(', ');

        return "Este cliente es {$etiquetas}, y ninguno de esos tipos tiene lista de precios. "
            . 'Marca «define precio» en Segmentación, o cámbiale el tipo de contacto.';
    }
}
