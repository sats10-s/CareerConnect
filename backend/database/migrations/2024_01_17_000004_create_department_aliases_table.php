<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Maps the college file's "Department" spellings ("AIML", "AI&ML", "MECH",
 * "Civil", ...) onto departments. `alias` is stored normalized (see
 * App\Models\DepartmentAlias::normalize()) so lookups are exact.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('department_aliases', function (Blueprint $table) {
            $table->id();
            $table->string('alias', 60)->unique();
            $table->foreignId('department_id')->constrained('departments')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('department_aliases');
    }
};
