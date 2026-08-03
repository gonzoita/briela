<?php

use App\Models\Op;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ops', function (Blueprint $table) {
            if (!Schema::hasColumn('ops', 'token_publico')) {
                $table->string('token_publico', 60)->nullable()->unique()->after('numero');
            }
        });

        // Backfill existing records
        if (Schema::hasColumn('ops', 'token_publico')) {
            Op::whereNull('token_publico')->each(function ($op) {
                $op->updateQuietly(['token_publico' => Str::random(40)]);
            });
        }
    }

    public function down(): void
    {
        Schema::table('ops', function (Blueprint $table) {
            if (Schema::hasColumn('ops', 'token_publico')) {
                $table->dropColumn('token_publico');
            }
        });
    }
};
