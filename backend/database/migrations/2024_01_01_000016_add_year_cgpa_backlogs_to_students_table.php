<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            // Nullable at the DB level (not every existing/seeded row is
            // guaranteed to have these) - it's the registration and
            // profile-update Form Requests that actually require `year`
            // and enforce its 1-4 range; the column itself stays
            // permissive so this migration can't break rows created
            // before this field existed.
            $table->unsignedTinyInteger('year')->nullable()->after('batch_id');
            $table->unsignedDecimal('cgpa', 4, 2)->nullable()->after('year');
            $table->unsignedTinyInteger('backlogs')->nullable()->default(0)->after('cgpa');
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropColumn(['year', 'cgpa', 'backlogs']);
        });
    }
};
