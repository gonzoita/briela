<?php

use Database\Seeders\CursoSistemaSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Carga el curso de inducción "Cómo usar el sistema".
 *
 * Va como migración y no solo como seeder porque el deploy corre
 * `php artisan migrate --force` pero NO corre seeders. En Briela esto importa
 * todavía más: cada instalación nueva de un cliente debe quedar con el curso
 * cargado sin que nadie entre al servidor a sembrarlo a mano.
 *
 * El seeder es idempotente: rehace el contenido del curso sin tocar las
 * inscripciones ni el progreso de quien ya lo empezó.
 *
 * OJO: una migración corre UNA sola vez. Si se corrige el texto de una
 * lección, hay que agregar otra migración que llame al seeder de nuevo, o
 * correr `php artisan db:seed --class=CursoSistemaSeeder` esa vez.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new CursoSistemaSeeder())->run();
    }

    public function down(): void
    {
        // A propósito no borra nada: eliminaría el curso y, con él, las
        // inscripciones, el progreso y los certificados de quien lo hubiera
        // presentado. Para retirarlo se desactiva desde la interfaz.
    }
};
