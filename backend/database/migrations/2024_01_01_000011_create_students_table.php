<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('roll_number', 32)->unique();
            $table->foreignId('department_id')->constrained('departments')->restrictOnDelete();
            $table->foreignId('batch_id')->constrained('batches')->restrictOnDelete();
            $table->string('phone', 20)->nullable();
            // `faculty` is created before `students` (see the previous
            // migration) specifically so this FK can be declared inline
            // here, rather than bolted on afterwards - adding a foreign
            // key to an already-existing table isn't portable across
            // database drivers (SQLite in particular, which the test
            // suite uses, doesn't support ALTER TABLE ADD CONSTRAINT).
            $table->foreignId('faculty_mentor_id')->nullable()->constrained('faculty')->nullOnDelete();
            $table->timestamps();

            $table->index('department_id');
            $table->index('batch_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('students');
    }
};
