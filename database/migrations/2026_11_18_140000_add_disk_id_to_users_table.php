<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The disk this user's new files go to. Null means the default disk, so nobody has to be configured.
        // Existing blobs never move when it changes.
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('disk_id')->nullable()->after('quota_bytes')->constrained('storage_disks')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('disk_id');
        });
    }
};
