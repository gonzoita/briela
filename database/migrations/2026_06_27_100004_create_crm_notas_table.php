<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('crm_notas')) {
            Schema::create('crm_notas', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('lead_id');
                $table->foreign('lead_id')->references('id')->on('crm_leads')->cascadeOnDelete();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
                $table->text('contenido');
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_notas');
    }
};
