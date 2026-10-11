<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportacionCosto extends Model
{
    protected $table = 'importacion_costos';

    /** El flete y el seguro forman parte del valor CIF, que es la base del arancel; el resto, no. */
    public const TIPOS = ['flete' => 'Flete internacional', 'seguro' => 'Seguro', 'gasto' => 'Gasto de nacionalización'];

    protected $fillable = ['importacion_id', 'tipo', 'concepto', 'valor', 'en_moneda_base', 'orden'];

    protected $casts = ['valor' => 'decimal:4', 'en_moneda_base' => 'boolean'];

    public function importacion(): BelongsTo
    {
        return $this->belongsTo(Importacion::class);
    }
}
