<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un pago de comisiones: varias, de un vendedor, liquidadas de una sola vez.
 *
 * En borrador se puede armar y deshacer. Cuando se marca pagada, sus comisiones quedan
 * liquidadas y el documento se cierra — es el registro de que esa plata salió.
 */
class LiquidacionComision extends Model
{
    protected $table = 'liquidaciones_comision';

    protected $fillable = ['numero', 'user_id', 'total', 'estado', 'fecha', 'notas', 'pagada_at', 'creado_por'];

    protected $casts = [
        'total'     => 'float',
        'fecha'     => 'date',
        'pagada_at' => 'datetime',
    ];

    public function vendedor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function creadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por');
    }

    public function comisiones(): HasMany
    {
        return $this->hasMany(ComisionVendedor::class, 'liquidacion_id');
    }

    /** El consecutivo, con el mismo criterio que el resto de los documentos. */
    public static function generarNumero(): string
    {
        $ultimo = static::withoutGlobalScopes()->orderByDesc('id')->value('numero');
        $n      = $ultimo ? ((int) preg_replace('/\D/', '', substr($ultimo, -5))) + 1 : 1;

        return 'LIQ-' . now()->format('Y') . '-' . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
    }

    public function recalcularTotal(): void
    {
        $this->update(['total' => (float) $this->comisiones()->sum('total_comision')]);
    }
}
