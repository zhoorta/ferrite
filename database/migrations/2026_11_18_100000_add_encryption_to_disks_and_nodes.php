<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A disk with `encrypt` set writes every new blob encrypted (docs/encryption.md).
        Schema::table('storage_disks', function (Blueprint $table) {
            $table->boolean('encrypt')->default(false)->after('is_default');
        });

        // The master key id a file's blob is encrypted with; null is a plaintext blob. Per file, not per disk,
        // so a disk can hold both while `files:encrypt` converts it, and a reader never has to guess.
        Schema::table('nodes', function (Blueprint $table) {
            $table->unsignedSmallInteger('encryption_key_id')->nullable()->after('sha256');
        });
    }

    public function down(): void
    {
        Schema::table('nodes', fn (Blueprint $table) => $table->dropColumn('encryption_key_id'));
        Schema::table('storage_disks', fn (Blueprint $table) => $table->dropColumn('encrypt'));
    }
};
