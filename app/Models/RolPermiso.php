<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RolPermiso extends Model
{
    protected $table = 'rol_permiso';

    public $timestamps = false;

    protected $fillable = ['rol_id', 'permiso'];

    public function rol(): BelongsTo
    {
        return $this->belongsTo(Rol::class, 'rol_id');
    }
}
