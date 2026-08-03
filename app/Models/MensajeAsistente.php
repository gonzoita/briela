<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MensajeAsistente extends Model
{
    protected $table = 'asistente_mensajes';

    /** Cuántos mensajes se conservan por usuario. */
    public const LIMITE = 100;

    /** Cuántos se le mandan a la IA como contexto de la conversación. */
    public const CONTEXTO = 10;

    protected $fillable = ['user_id', 'rol', 'contenido', 'consulta'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Los últimos mensajes de un usuario, en orden cronológico.
     *
     * @return \Illuminate\Support\Collection<int, array{rol:string,contenido:string,consulta:?string}>
     */
    public static function historial(int $userId, int $cuantos = 30)
    {
        return static::where('user_id', $userId)
            ->latest('id')
            ->take($cuantos)
            ->get()
            ->reverse()
            ->map(fn (self $m) => [
                'rol'       => $m->rol,
                'contenido' => $m->contenido,
                'consulta'  => $m->consulta,
            ])
            ->values();
    }

    /**
     * Guarda un mensaje y recorta el historial viejo.
     *
     * Sin el recorte, un usuario que use mucho el asistente acumularía miles
     * de filas que nadie va a leer.
     */
    public static function registrar(int $userId, string $rol, string $contenido, ?string $consulta = null): void
    {
        static::create([
            'user_id'   => $userId,
            'rol'       => $rol,
            'contenido' => $contenido,
            'consulta'  => $consulta,
        ]);

        static::recortar($userId);
    }

    public static function recortar(int $userId): void
    {
        $total = static::where('user_id', $userId)->count();

        if ($total <= self::LIMITE) {
            return;
        }

        // Se borra por id y no por fecha: dos mensajes del mismo segundo
        // (pregunta y respuesta) tienen el mismo created_at.
        $corte = static::where('user_id', $userId)
            ->latest('id')
            ->skip(self::LIMITE)
            ->take(1)
            ->value('id');

        if ($corte) {
            static::where('user_id', $userId)->where('id', '<=', $corte)->delete();
        }
    }
}
