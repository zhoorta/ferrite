<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('user')->after('password');
            // Null quota means unlimited.
            $table->unsignedBigInteger('quota_bytes')->nullable()->after('role');
            $table->unsignedBigInteger('used_bytes')->default(0)->after('quota_bytes');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['role', 'quota_bytes', 'used_bytes']);
        });
    }
};
