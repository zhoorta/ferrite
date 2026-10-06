<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('uploads', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Folder the file lands in (any folders from a relative path are created up front).
            $table->foreignId('parent_id')->nullable()->constrained('nodes')->cascadeOnDelete();
            $table->string('name');
            $table->unsignedBigInteger('size');
            $table->unsignedBigInteger('offset')->default(0);
            // Lets a re-selected file resume an unfinished upload.
            $table->string('fingerprint', 100)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'parent_id', 'name', 'size']);
            $table->index('updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('uploads');
    }
};
