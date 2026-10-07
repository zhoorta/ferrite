<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Several file nodes of one owner may point at the same blob (deduplication), so a blob key
     * is no longer unique; how many nodes use it is counted from this table.
     */
    public function up(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->dropUnique(['path']);
            $table->index(['disk_id', 'path']);
            $table->index(['owner_id', 'sha256', 'size']);
        });
    }

    public function down(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->dropIndex(['owner_id', 'sha256', 'size']);
            $table->dropIndex(['disk_id', 'path']);
            $table->unique('path');
        });
    }
};
