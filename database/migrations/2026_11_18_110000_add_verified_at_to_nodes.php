<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // When the integrity scrub last read this file's blob back and found it matching its sha256.
        // Nodes that share a blob share the value; the scrub reads the least recently verified blobs first.
        Schema::table('nodes', function (Blueprint $table) {
            $table->timestamp('verified_at')->nullable()->after('encryption_key_id');
        });
    }

    public function down(): void
    {
        Schema::table('nodes', fn (Blueprint $table) => $table->dropColumn('verified_at'));
    }
};
