<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Una dirección a la que no se le vuelve a escribir: rebotó, se quejó o se dio de baja. */
class CorreoSupresion extends Model
{
    protected $table = 'correo_supresiones';

    protected $fillable = ['email', 'motivo', 'detalle'];

    public static function suprimido(string $email): bool
    {
        return static::where('email', strtolower(trim($email)))->exists();
    }
}
