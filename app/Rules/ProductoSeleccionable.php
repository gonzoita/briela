<?php

namespace App\Rules;

use App\Models\Producto;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ProductoSeleccionable implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $producto = Producto::find($value);

        if (!$producto || $producto->es_padre) {
            $fail('Selecciona una variante o un producto simple, no un producto padre.');
        }
    }
}
