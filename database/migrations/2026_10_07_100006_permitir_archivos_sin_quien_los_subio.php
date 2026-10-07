<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `archivos.subido_por` deja de ser obligatorio.
 *
 * **Por qué.** La columna nació cuando todos los archivos entraban por un formulario: alguien
 * del sistema elegía un archivo y lo subía, así que siempre había a quién atribuirlo. Con la
 * bandeja, los archivos llegan **de afuera**: la foto que un cliente manda por WhatsApp no la
 * subió ningún usuario, y el único valor honesto es ninguno.
 *
 * Poner ahí el usuario de la petición no serviría: el webhook no tiene a nadie autenticado.
 * Y poner un usuario cualquiera —el primer administrador, por ejemplo— haría que Multimedia
 * dijera que esa persona subió fotos que nunca vio.
 *
 * Es un cambio **que ensancha**: lo que antes se aceptaba se sigue aceptando, y pasa a
 * aceptarse también el nulo. Ninguna fila existente cambia. La llave foránea se conserva.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('archivos', 'subido_por')) {
            return;
        }

        Schema::table('archivos', function (Blueprint $tabla) {
            $tabla->foreignId('subido_por')->nullable()->change();
        });
    }

    public function down(): void
    {
        // A propósito vacío: volver a exigirlo fallaría en cualquier instalación que ya tenga
        // archivos llegados por la bandeja, y «arreglarlo» significaría inventarles un dueño.
    }
};
