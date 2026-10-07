<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Themes that were removed; their accounts move to the new default. */
    private const REMOVED = ['light', 'plum', 'wood', 'teal', 'forest', 'midnight', 'olive', 'berry', 'charcoal', 'sage', 'slate', 'bw'];

    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('palette', 16)->default('ferrite')->change();
        });

        DB::table('users')->whereIn('palette', self::REMOVED)->update(['palette' => 'ferrite']);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('palette', 16)->default('plum')->change();
        });
    }
};
