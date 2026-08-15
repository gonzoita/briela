<?php

namespace App\Rules;

use App\Models\Producto;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Un producto padre no se puede vender: lo que se vende es una de sus variantes.
 *
 * El mensaje nombra el ítem y el producto a propósito. Antes decía solo «Selecciona una
 * variante o un producto simple, no un producto padre», sin decir en qué línea, y eso lo
 * volvía inútil justo cuando más se necesita: en una cotización de ocho líneas, con el aviso
 * al final de la pantalla, quien lo leía revisaba la última cosa que había agregado y
 * concluía que el problema era esa. Puede llevar meses guardado en un documento viejo.
 *
 * Y sí pasa sin que nadie haga nada raro: se cotiza un producto simple, y meses después
 * alguien le agrega variantes. Ese producto se vuelve padre, y la cotización que lo tenía
 * deja de poder guardarse.
 */
class ProductoSeleccionable implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $producto = Producto::find($value);

        if ($producto && ! $producto->es_padre) {
            return;
        }

        $linea = $this->numeroDeLinea($attribute);
        $donde = $linea ? "El ítem {$linea}" : 'Un ítem';

        if (! $producto) {
            $fail("{$donde} apunta a un producto que ya no existe. Bórralo de la lista y agrégalo de nuevo.");

            return;
        }

        $fail("{$donde} («{$producto->nombre}») quedó apuntando a un producto con variantes, y lo que "
            .'se vende es una variante concreta. Bórralo de la lista y elige la variante que va.');
    }

    /**
     * El número de línea que ve el usuario, a partir de `items.3.producto_id`.
     *
     * El índice del arreglo empieza en cero y la lista en pantalla empieza en uno: decirle
     * «el ítem 3» a quien está mirando el cuarto es mandarlo a revisar el equivocado.
     */
    private function numeroDeLinea(string $attribute): ?int
    {
        return preg_match('/\.(\d+)\./', $attribute, $m) ? ((int) $m[1] + 1) : null;
    }
}
