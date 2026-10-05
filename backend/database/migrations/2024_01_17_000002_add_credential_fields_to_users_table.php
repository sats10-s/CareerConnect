<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Student login by JNTU No:
 *  - must_change_password: first password is the registered mobile number
 *    (or a one-time password), and nothing else works until it is changed.
 *  - credentials_issued_at: when that first password was issued (import or
 *    admin reset). An unclaimed first password stops working after 30 days.
 *  - password_changed_at: when the user last set their own password.
 *  - failed_logins / locked_until: per-account lockout.
 *
 * `email` becomes nullable: imported students have no college email - they
 * sign in with their JNTU No. Staff keep signing in by email.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('must_change_password')->default(false)->after('password');
            $table->timestamp('credentials_issued_at')->nullable()->after('must_change_password');
            $table->timestamp('password_changed_at')->nullable()->after('credentials_issued_at');
            $table->unsignedSmallInteger('failed_logins')->default(0)->after('password_changed_at');
            $table->timestamp('locked_until')->nullable()->after('failed_logins');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['must_change_password', 'credentials_issued_at', 'password_changed_at', 'failed_logins', 'locked_until']);
        });
    }
};
