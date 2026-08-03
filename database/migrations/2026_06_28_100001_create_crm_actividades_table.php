<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('crm_actividades', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('lead_id');
            $table->foreign('lead_id')->references('id')->on('crm_leads')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->string('tipo', 50);
            $table->string('descripcion', 500);
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_actividades');
    }
};
