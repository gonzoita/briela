<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContactoCliente extends Model
{
    protected $table = 'contactos_cliente';

    protected $fillable = [
        'cliente_id',
        'nombre',
        'apellido',
        'cargo',
        'email',
        'telefono',
        'celular',
        'es_principal',
        'notas',
    ];

    protected $casts = [
        'es_principal' => 'boolean',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::saving(function (self $contacto) {
            if ($contacto->es_principal) {
                static::where('cliente_id', $contacto->cliente_id)
                    ->where('id', '!=', $contacto->id ?? 0)
                    ->update(['es_principal' => false]);
            }
        });
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function scopePrincipal($query)
    {
        return $query->where('es_principal', true);
    }
}
