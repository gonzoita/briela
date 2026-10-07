<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lo que le faltaba a WhatsApp para tener una bandeja donde atender.
 *
 * Hasta ahora los mensajes se guardaban y nadie los podía leer: no existía pantalla. Para que
 * una persona atienda de verdad hacen falta tres cosas que la tabla no tenía:
 *
 * - **`asignado_a`** — quién atiende ESTA conversación. El dueño del número ya decide a quién
 *   le llega el aviso, pero una línea central la atienden varios y hay que poder decir «esta es
 *   mía» sin reasignar el número entero.
 * - **`ultimo_entrante_at`** — cuándo escribió el cliente por última vez. Es lo único que dice
 *   si la **ventana de 24 horas** de Meta está abierta. Sin esa fecha hay que recorrer los
 *   mensajes de cada conversación para pintar una lista, y la bandeja de una empresa con mil
 *   conversaciones se vuelve impagable. Se puede deducir de los mensajes, pero deducirlo en
 *   cada carga es justo lo que la regla de velocidad prohíbe.
 * - **`archivada_at`** — sacarla de la vista sin perder el historial. Una bandeja sin esto se
 *   convierte en una lista infinita donde lo de hoy queda debajo de lo del año pasado.
 *
 * Y en los mensajes, `archivo_id` para el adjunto, `error` para saber por qué uno no salió, y
 * `plantilla` para dejar constancia de cuál se usó cuando la ventana estaba cerrada.
 *
 * Todo aditivo y con guarda por columna: esta migración tiene que poder correr sobre cualquier
 * instalación anterior, incluida una que ya la haya corrido.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_conversaciones', function (Blueprint $tabla) {
            if (! Schema::hasColumn('whatsapp_conversaciones', 'asignado_a')) {
                $tabla->foreignId('asignado_a')->nullable()->after('cliente_id')
                    ->constrained('users')->nullOnDelete();
            }

            if (! Schema::hasColumn('whatsapp_conversaciones', 'ultimo_entrante_at')) {
                $tabla->timestamp('ultimo_entrante_at')->nullable()->after('ultimo_mensaje_at');
            }

            if (! Schema::hasColumn('whatsapp_conversaciones', 'archivada_at')) {
                $tabla->timestamp('archivada_at')->nullable()->after('leido');
            }
        });

        Schema::table('whatsapp_mensajes', function (Blueprint $tabla) {
            if (! Schema::hasColumn('whatsapp_mensajes', 'archivo_id')) {
                $tabla->foreignId('archivo_id')->nullable()->after('url_media')
                    ->constrained('archivos')->nullOnDelete();
            }

            if (! Schema::hasColumn('whatsapp_mensajes', 'error')) {
                $tabla->text('error')->nullable()->after('estado');
            }

            if (! Schema::hasColumn('whatsapp_mensajes', 'plantilla')) {
                $tabla->string('plantilla')->nullable()->after('error');
            }
        });

        // La lista de la bandeja se ordena por la última actividad y filtra por sin leer. Sin
        // índice, cada apertura es un recorrido completo de la tabla que más crece del módulo.
        $this->indice('whatsapp_conversaciones', 'whatsapp_conv_bandeja_idx', ['archivada_at', 'ultimo_mensaje_at']);
        $this->indice('whatsapp_mensajes', 'whatsapp_msj_hilo_idx', ['whatsapp_conversacion_id', 'id']);

        $this->rellenarUltimoEntrante();
    }

    /**
     * Las conversaciones que ya existían no tienen `ultimo_entrante_at`, y sin él la pantalla
     * diría que la ventana está cerrada en todas: nadie podría contestarle a quien escribió
     * hace diez minutos. Se deduce una sola vez, del último mensaje entrante de cada una.
     */
    private function rellenarUltimoEntrante(): void
    {
        DB::table('whatsapp_conversaciones')
            ->whereNull('ultimo_entrante_at')
            ->orderBy('id')
            ->chunkById(500, function ($conversaciones) {
                foreach ($conversaciones as $conversacion) {
                    $ultimo = DB::table('whatsapp_mensajes')
                        ->where('whatsapp_conversacion_id', $conversacion->id)
                        ->where('direccion', 'entrante')
                        ->max('created_at');

                    if ($ultimo) {
                        DB::table('whatsapp_conversaciones')
                            ->where('id', $conversacion->id)
                            ->update(['ultimo_entrante_at' => $ultimo]);
                    }
                }
            });
    }

    private function indice(string $tabla, string $nombre, array $columnas): void
    {
        $existentes = collect(Schema::getIndexes($tabla))->pluck('name');

        if (! $existentes->contains($nombre)) {
            Schema::table($tabla, fn (Blueprint $t) => $t->index($columnas, $nombre));
        }
    }

    public function down(): void
    {
        // A propósito vacío: en un producto instalado, deshacer una migración borra datos de
        // clientes a los que no tenemos acceso. Las columnas nuevas no estorban a nadie.
    }
};
