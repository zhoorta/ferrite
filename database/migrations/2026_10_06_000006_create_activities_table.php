<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activities', function (Blueprint $table) {
            $table->id();
            // Whose files this is about, so owners also see what collaborators and guests did.
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            // Null for guests using a share link, or after the user was deleted.
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('node_id')->nullable()->constrained()->nullOnDelete();
            // Names are copied, so the log still reads well after renames and deletions.
            $table->string('node_name');
            $table->string('action', 40);
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['owner_id', 'created_at']);
            $table->index(['actor_id', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activities');
    }
};
