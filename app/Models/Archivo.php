<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Archivo extends Model
{
    protected $fillable = [
        'nombre_original', 'nombre_archivo', 'ruta', 'tipo_mime',
        'extension', 'tamano', 'categoria', 'archivable_type',
        'archivable_id', 'subido_por', 'descripcion',
        'drive_id', 'drive_url', 'storage',
    ];

    public function archivable()
    {
        return $this->morphTo();
    }

    public function subidoPor()
    {
        return $this->belongsTo(User::class, 'subido_por');
    }

    public function getTamanoFormateadoAttribute(): string
    {
        $bytes = $this->tamano;
        if ($bytes < 1024)    return $bytes . ' B';
        if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KB';
        return round($bytes / 1048576, 1) . ' MB';
    }

    public function getUrlAttribute(): string
    {
        if ($this->storage === 'drive') {
            return $this->ruta;
        }
        return Storage::disk('public')->url($this->ruta);
    }

    public function getEsImagenAttribute(): bool
    {
        return in_array($this->extension, ['jpg', 'jpeg', 'png', 'gif', 'webp']);
    }
}
