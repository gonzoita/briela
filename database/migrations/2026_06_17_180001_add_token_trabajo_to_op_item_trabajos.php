<?php

use App\Models\OpItemTrabajo;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('op_item_trabajos', function (Blueprint $table) {
            if (!Schema::hasColumn('op_item_trabajos', 'token_trabajo')) {
                $table->string('token_trabajo', 60)->nullable()->unique()->after('total_unidades');
            }
        });

        // Backfill existing records
        if (Schema::hasColumn('op_item_trabajos', 'token_trabajo')) {
            OpItemTrabajo::whereNull('token_trabajo')->each(function ($t) {
                $t->updateQuietly(['token_trabajo' => Str::random(40)]);
            });
        }
    }

    public function down(): void
    {
        Schema::table('op_item_trabajos', function (Blueprint $table) {
            if (Schema::hasColumn('op_item_trabajos', 'token_trabajo')) {
                $table->dropColumn('token_trabajo');
            }
        });
    }
};
