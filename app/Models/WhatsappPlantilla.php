<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * El espejo local de una plantilla aprobada en Meta.
 *
 * No se escribe desde acá: se crea y se aprueba en el Business Manager, y este registro es lo
 * que el sistema sabe de ella. Ver el encabezado de su migración.
 */
class WhatsappPlantilla extends Model
{
    protected $table = 'whatsapp_plantillas';

    protected $fillable = [
        'nombre', 'idioma', 'categoria', 'estado', 'externo_id',
        'encabezado', 'cuerpo', 'pie', 'variables', 'componentes', 'sincronizada_at',
    ];

    protected $casts = [
        'componentes'     => 'array',
        'variables'       => 'integer',
        'sincronizada_at' => 'datetime',
    ];

    /**
     * Solo las aprobadas se pueden enviar.
     *
     * Una en revisión o rechazada existe en Meta y aparece al sincronizar, pero mandarla
     * devuelve un error. Ofrecerla en el selector es prometer algo que no va a salir.
     */
    public function scopeAprobadas(Builder $query): Builder
    {
        return $query->where('estado', 'APPROVED');
    }

    public function aprobada(): bool
    {
        return $this->estado === 'APPROVED';
    }

    /**
     * El texto con las variables ya reemplazadas, para la vista previa.
     *
     * Lo que falte se deja como `{{n}}`: ver el hueco es más útil que ver el mensaje a medias
     * y creer que así va a salir.
     *
     * @param  array<int, string>  $valores
     */
    public function previsualizar(array $valores = []): string
    {
        $partes = array_filter([$this->encabezado, $this->cuerpo, $this->pie]);
        $texto  = implode("\n\n", $partes);

        foreach (array_values($valores) as $indice => $valor) {
            $texto = str_replace('{{' . ($indice + 1) . '}}', (string) $valor, $texto);
        }

        return $texto;
    }

    /**
     * Cuántas variables pide un cuerpo de plantilla.
     *
     * Se cuentan los números distintos y no las apariciones: `{{1}}` puede salir dos veces en
     * el mismo texto y sigue siendo UN dato que pedirle a quien la manda.
     */
    public static function contarVariables(?string ...$textos): int
    {
        $numeros = [];

        foreach ($textos as $texto) {
            preg_match_all('/\{\{\s*(\d+)\s*\}\}/', (string) $texto, $coincidencias);
            $numeros = array_merge($numeros, $coincidencias[1] ?? []);
        }

        return count(array_unique($numeros));
    }
}
