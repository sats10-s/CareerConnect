<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only audit trail of every internship assignment STATUS
 * change (create/activate/complete/cancel) - see
 * App\Models\InternshipAssignmentStatusHistory and
 * App\Services\InternshipAssignmentService (the only writer of this
 * table). No `updated_at` (UPDATED_AT = null on the model) - a history
 * row is only ever inserted, never modified, mirroring
 * application_status_history/interview_status_history/
 * placement_drive_status_history exactly.
 *
 * `from_status` is nullable for the very first row an assignment ever
 * gets (null -> ASSIGNED, written the moment the assignment is
 * created).
 *
 * Faculty REASSIGNMENT is deliberately NOT recorded here - see
 * internship_assignment_faculty_history, a dedicated table, so a
 * faculty change is never hidden inside a generic status/remarks
 * string (per the module spec's explicit instruction).
 *
 * The internship_assignment_id foreign key is given an explicit,
 * short constraint name (`ia_status_history_assignment_fk`) instead
 * of Laravel's auto-generated one. The auto-generated name would be
 * `internship_assignment_status_history_internship_assignment_id_foreign`
 * (69 characters), which exceeds MySQL's 64-character identifier
 * limit and fails with SQLSTATE[42000] error 1059 on this table's
 * naming combination specifically (no other FK in this module hits
 * the limit). Column, referenced table/id, and cascadeOnDelete()
 * behavior are unchanged - only the constraint's name changed.
 *
 * CREATE-OR-REPAIR: on some local databases an earlier run of this
 * migration (before the fix above existed) got as far as physically
 * creating this table - Laravel compiles `Schema::create()` as a bare
 * CREATE TABLE statement (columns + primary key only) followed by
 * separate ALTER TABLE statements for each foreign key/index command,
 * each committed immediately since MySQL auto-commits DDL - before
 * failing on the too-long auto-generated constraint name for
 * internship_assignment_id. That left the table (and possibly the
 * `changed_by` foreign key and/or the composite index, depending on
 * exactly how far it got) in place while Laravel still considered the
 * migration un-run. Re-running `Schema::create()` against that table
 * would now fail with "table already exists", and dropping/recreating
 * it would be destructive. So `up()` branches: if the table does not
 * exist, create it (with both foreign keys and the index) exactly as
 * originally designed; if it already exists, leave every existing
 * column/constraint/index untouched and add back only whichever of
 * the foreign keys/index are actually missing, checked directly
 * against information_schema so nothing is re-declared or duplicated.
 */
return new class extends Migration
{
    private const TABLE = 'internship_assignment_status_history';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            Schema::create(self::TABLE, function (Blueprint $table) {
                $table->id();
                $table->foreignId('internship_assignment_id');
                $table->string('from_status', 20)->nullable();
                $table->string('to_status', 20);
                $table->foreignId('changed_by')->nullable();
                $table->text('remarks')->nullable();
                $table->timestamp('created_at')->useCurrent();
            });
        }

        // Whether the table was just created above or already existed
        // from a prior partial run, converge on the same end state by
        // adding only whatever is actually missing.
        if (! $this->columnHasForeignKey('internship_assignment_id')) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->foreign('internship_assignment_id', 'ia_status_history_assignment_fk')
                    ->references('id')->on('internship_assignments')
                    ->cascadeOnDelete();
            });
        }

        if (! $this->columnHasForeignKey('changed_by')) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->foreign('changed_by')
                    ->references('id')->on('users')
                    ->nullOnDelete();
            });
        }

        if (! $this->indexExists('ia_status_history_assignment_created_idx')) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->index(['internship_assignment_id', 'created_at'], 'ia_status_history_assignment_created_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }

    /**
     * True if `$column` on this table already has a foreign key
     * constraint, under any name. Only meaningful (and only called in
     * practice) on MySQL - the repair scenario this checks for cannot
     * occur on the SQLite connection the test suite uses, since
     * RefreshDatabase always starts from a database where this table
     * does not exist yet, taking the plain Schema::create() path
     * above instead.
     */
    private function columnHasForeignKey(string $column): bool
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return false;
        }

        return DB::table('information_schema.KEY_COLUMN_USAGE')
            ->whereRaw('TABLE_SCHEMA = DATABASE()')
            ->where('TABLE_NAME', self::TABLE)
            ->where('COLUMN_NAME', $column)
            ->whereNotNull('REFERENCED_TABLE_NAME')
            ->exists();
    }

    /**
     * True if an index named `$indexName` already exists on this
     * table. Same MySQL-only scoping as columnHasForeignKey() above,
     * for the same reason.
     */
    private function indexExists(string $indexName): bool
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return false;
        }

        return DB::table('information_schema.STATISTICS')
            ->whereRaw('TABLE_SCHEMA = DATABASE()')
            ->where('TABLE_NAME', self::TABLE)
            ->where('INDEX_NAME', $indexName)
            ->exists();
    }
};
