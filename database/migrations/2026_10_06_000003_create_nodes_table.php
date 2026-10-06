<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('nodes')->cascadeOnDelete();
            $table->string('type', 10);
            $table->string('name');
            $table->foreignId('disk_id')->nullable()->constrained('storage_disks')->restrictOnDelete();
            // Random blob key on the disk; names and folders live only in this table.
            $table->string('path')->nullable()->unique();
            $table->unsignedBigInteger('size')->default(0);
            $table->string('mime')->nullable();
            $table->char('sha256', 64)->nullable();
            $table->timestamp('trashed_at')->nullable()->index();
            $table->timestamps();

            // Unique name per parent among non-trashed nodes. NULLs never collide in a unique
            // index, so root nodes (no parent) and trashed nodes are mapped to non-null/null keys.
            $table->unsignedBigInteger('parent_key')->virtualAs('coalesce(parent_id, 0)');
            $table->unsignedTinyInteger('active_key')->nullable()->virtualAs('case when trashed_at is null then 1 end');
            $table->unique(['owner_id', 'parent_key', 'name', 'active_key'], 'nodes_unique_active_name');

            $table->index(['parent_id', 'trashed_at']);
        });

        Schema::create('node_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('node_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('permission', 10);
            $table->timestamps();

            $table->unique(['node_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('node_user');
        Schema::dropIfExists('nodes');
    }
};
