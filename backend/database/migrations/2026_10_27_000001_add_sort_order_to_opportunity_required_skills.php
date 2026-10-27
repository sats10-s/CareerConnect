<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('opportunity_required_skills', function (Blueprint $table) {
            $table->unsignedInteger('sort_order')->default(0)->after('skill_name');
        });

        // Existing rows have no persisted request order. Preserve a stable,
        // deterministic legacy order by their insertion IDs per opportunity.
        $positionByOpportunity = [];
        DB::table('opportunity_required_skills')
            ->orderBy('opportunity_type')
            ->orderBy('opportunity_id')
            ->orderBy('id')
            ->select(['id', 'opportunity_type', 'opportunity_id'])
            ->get()
            ->each(function ($row) use (&$positionByOpportunity): void {
                $key = $row->opportunity_type.'#'.$row->opportunity_id;
                $position = $positionByOpportunity[$key] ?? 0;
                DB::table('opportunity_required_skills')->where('id', $row->id)->update(['sort_order' => $position]);
                $positionByOpportunity[$key] = $position + 1;
            });
    }

    public function down(): void
    {
        Schema::table('opportunity_required_skills', function (Blueprint $table) {
            $table->dropColumn('sort_order');
        });
    }
};
