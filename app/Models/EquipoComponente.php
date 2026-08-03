<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EquipoComponente extends Model
{
    protected $table = 'equipo_componentes';

    protected $fillable = ['equipo_id', 'nombre', 'referencia', 'unidad', 'cantidad', 'descripcion'];

    public function equipo()
    {
        return $this->belongsTo(EquipoMantenimiento::class, 'equipo_id');
    }
}
