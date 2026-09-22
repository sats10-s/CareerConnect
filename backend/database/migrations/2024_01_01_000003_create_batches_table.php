<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('batches', function (Blueprint $table) {
            $table->id();
            $table->string('name', 32); // e.g. "2022-2026"
            $table->unsignedSmallInteger('start_year');
            $table->unsignedSmallInteger('end_year');
            $table->timestamps();

            $table->unique(['start_year', 'end_year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('batches');
    }
};
