<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shares', function (Blueprint $table) {
            // 'view' (the default) or 'dropbox': guests add files to a folder and see nothing in it.
            $table->string('kind', 10)->default('view')->after('token');
            // Drop-box only: a cap on the bytes the link may receive in total, and what it has received.
            $table->unsignedBigInteger('max_bytes')->nullable()->after('allow_download');
            $table->unsignedBigInteger('received_bytes')->default(0)->after('max_bytes');
        });

        Schema::table('uploads', function (Blueprint $table) {
            // Set for an upload made by a guest through a drop-box link (user_id is then the folder owner).
            $table->foreignId('share_id')->nullable()->after('user_id')->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('uploads', function (Blueprint $table) {
            $table->dropConstrainedForeignId('share_id');
        });

        Schema::table('shares', function (Blueprint $table) {
            $table->dropColumn(['kind', 'max_bytes', 'received_bytes']);
        });
    }
};
