<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The earlier contents of a file, kept when it is replaced (docs/versions.md). The newest content lives on
        // the node itself; a version is a stored blob that used to be it. Versions count against the owner's quota.
        Schema::create('node_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('node_id')->constrained()->cascadeOnDelete();
            $table->foreignId('disk_id')->constrained('storage_disks')->restrictOnDelete();
            $table->string('path');
            $table->unsignedBigInteger('size');
            $table->string('mime')->nullable();
            $table->string('sha256', 64)->nullable();
            $table->unsignedSmallInteger('encryption_key_id')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('content_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['node_id', 'id']);
            $table->index(['disk_id', 'path']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('node_versions');
    }
};
