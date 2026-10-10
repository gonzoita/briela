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

        // Con la misma tolerancia a espacios que `contarVariables`: Meta acepta `{{ 1 }}` y, con
        // un `str_replace` exacto, el contador decía «un dato» y la vista previa no lo
        // reemplazaba nunca — se veía el hueco y parecía que el dato escrito no servía.
        foreach (array_values($valores) as $indice => $valor) {
            // Con `preg_replace` a secas, un dato que contenga `$1` o `\1` se interpretaría
            // como referencia a un grupo y saldría otra cosa. El valor lo escribe quien manda
            // la plantilla —o viene de un documento—, así que no se puede dar por seguro.
            $texto = preg_replace_callback(
                '/\{\{\s*' . ($indice + 1) . '\s*\}\}/',
                fn () => (string) $valor,
                $texto,
            );
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
