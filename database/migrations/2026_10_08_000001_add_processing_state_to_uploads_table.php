<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Once the last chunk is in, a queued job hashes the file and copies it to its disk. The row
     * stays (receiving -> processing -> done or failed) so the browser can follow along; it also
     * remembers the blob key it is writing, so a crashed job can be cleaned up afterwards.
     */
    public function up(): void
    {
        Schema::table('uploads', function (Blueprint $table) {
            $table->string('status', 12)->default('receiving')->after('fingerprint');
            $table->text('error')->nullable()->after('status');
            $table->foreignId('node_id')->nullable()->after('error')->constrained('nodes')->nullOnDelete();
            $table->unsignedBigInteger('disk_id')->nullable()->after('node_id');
            $table->string('blob_key')->nullable()->after('disk_id');
            // When the job picked it up; waiting in the queue does not count towards the timeout.
            $table->timestamp('started_at')->nullable()->after('blob_key');

            $table->index(['status', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::table('uploads', function (Blueprint $table) {
            $table->dropIndex(['status', 'updated_at']);
            $table->dropConstrainedForeignId('node_id');
            $table->dropColumn(['status', 'error', 'disk_id', 'blob_key', 'started_at']);
        });
    }
};
