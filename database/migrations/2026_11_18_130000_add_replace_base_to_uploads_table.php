<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // For an API replace: the sha256 of the content the client's copy was based on. The upload only replaces the
        // file if it still has that content when the new one is stored (docs/api.md).
        Schema::table('uploads', function (Blueprint $table) {
            $table->string('replace_base', 64)->nullable()->after('replace');
        });
    }

    public function down(): void
    {
        Schema::table('uploads', fn (Blueprint $table) => $table->dropColumn('replace_base'));
    }
};
