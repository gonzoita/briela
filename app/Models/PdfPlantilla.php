<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PdfPlantilla extends Model
{
    protected $table = 'pdf_plantillas';

    protected $fillable = [
        'modulo', 'nombre', 'descripcion', 'html',
        'bloques_header', 'bloques_body', 'bloques_footer', 'modo_editor',
        'config_tabla', 'papel', 'orientacion', 'ancho_mm', 'alto_mm',
        'es_default', 'activa', 'creado_por',
    ];

    protected $casts = [
        'config_tabla'   => 'array',
        'bloques_header' => 'array',
        'bloques_body'   => 'array',
        'bloques_footer' => 'array',
        'es_default'     => 'boolean',
        'activa'         => 'boolean',
    ];

    public function creadoPor()
    {
        return $this->belongsTo(User::class, 'creado_por');
    }

    public function marcarComoDefault(): void
    {
        static::where('modulo', $this->modulo)
            ->where('id', '!=', $this->id)
            ->update(['es_default' => false]);
        $this->update(['es_default' => true]);
    }

    public static function defaultParaModulo(string $modulo): ?self
    {
        return static::where('modulo', $modulo)
            ->where('activa', true)
            ->where('es_default', true)
            ->first()
            ?? static::where('modulo', $modulo)
            ->where('activa', true)
            ->latest()
            ->first();
    }
}
