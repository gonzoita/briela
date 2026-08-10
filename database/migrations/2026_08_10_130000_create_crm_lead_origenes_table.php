<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * De dónde llegó cada lead — en plural.
 *
 * Hasta ahora el origen era un solo campo de texto en `crm_leads`. Con eso, alguien
 * que escribe por WhatsApp y después llena el formulario de la web entraba dos veces
 * al embudo, o se le sobreescribía el primer origen. Y saber por dónde llega la
 * gente es justamente lo que dice dónde vale la pena invertir.
 *
 * Ahora un lead tiene tantos orígenes como veces se haya acercado, cada uno con su
 * fecha y su campaña. Las columnas viejas de `crm_leads` se conservan —guardan el
 * **primer** contacto, que es el que cuenta para atribuir— y no se tocan: hay
 * informes y pantallas que las leen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_lead_origenes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained('crm_leads')->cascadeOnDelete();

            // El canal es texto y no un enum a propósito: mañana entra un canal
            // nuevo y no debería hacer falta migrar la base de cada cliente.
            $table->string('canal', 40);
            $table->string('detalle', 200)->nullable();

            $table->string('pagina', 500)->nullable();
            $table->string('utm_source', 150)->nullable();
            $table->string('utm_medium', 150)->nullable();
            $table->string('utm_campaign', 150)->nullable();

            // El identificador que trae el canal de origen: el id del mensaje de
            // WhatsApp, del lead de Meta, del envío del formulario. Sirve para no
            // registrar dos veces el mismo contacto si el webhook se repite.
            $table->string('referencia_externa', 190)->nullable();

            $table->timestamps();

            $table->index(['lead_id', 'canal']);
            $table->index(['canal', 'created_at']);
            $table->unique(['canal', 'referencia_externa'], 'origen_sin_repetir');
        });

        // El origen que ya está guardado en cada lead pasa a ser su primer origen.
        // Sin esto, los leads que ya existen se verían como llegados de la nada.
        $existentes = DB::table('crm_leads')
            ->select('id', 'fuente', 'pagina_origen', 'utm_source', 'utm_medium', 'utm_campaign', 'created_at')
            ->whereNull('deleted_at')
            ->get();

        foreach ($existentes as $lead) {
            DB::table('crm_lead_origenes')->insert([
                'lead_id'      => $lead->id,
                'canal'        => self::canalDesdeTexto($lead->fuente),
                'detalle'      => $lead->fuente,
                'pagina'       => $lead->pagina_origen,
                'utm_source'   => $lead->utm_source,
                'utm_medium'   => $lead->utm_medium,
                'utm_campaign' => $lead->utm_campaign,
                'created_at'   => $lead->created_at,
                'updated_at'   => $lead->created_at,
            ]);
        }
    }

    /**
     * Traduce el texto libre que se venía guardando a un canal conocido.
     *
     * Lo que no se reconozca queda como "otro" con su texto original en el detalle:
     * es preferible a inventar una clasificación que nadie escribió.
     */
    private static function canalDesdeTexto(?string $fuente): string
    {
        $t = mb_strtolower(trim((string) $fuente));

        return match (true) {
            $t === ''                              => 'otro',
            str_contains($t, 'whatsapp')           => 'whatsapp',
            str_contains($t, 'formulario')         => 'formulario',
            str_contains($t, 'sitio') || str_contains($t, 'web') => 'web',
            str_contains($t, 'instagram')          => 'instagram',
            str_contains($t, 'facebook') || str_contains($t, 'meta') => 'facebook',
            str_contains($t, 'google')             => 'google',
            str_contains($t, 'llamada') || str_contains($t, 'telefon') => 'telefono',
            str_contains($t, 'correo') || str_contains($t, 'email') || str_contains($t, 'mail') => 'correo',
            str_contains($t, 'referid') || str_contains($t, 'recomend') => 'referido',
            str_contains($t, 'feria') || str_contains($t, 'evento')     => 'evento',
            default                                => 'otro',
        };
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_lead_origenes');
    }
};
