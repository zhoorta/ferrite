<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('uploads', function (Blueprint $table) {
            // Uploads started through an API token belong to that token: only it can send chunks or cancel.
            $table->foreignId('api_token_id')->nullable()->after('share_id')->constrained('personal_access_tokens')->nullOnDelete();
            $table->string('api_token_name')->nullable()->after('api_token_id');
            // 'keep' stores a name that appeared meanwhile as "name (2)"; 'fail' (the API) refuses the upload instead.
            $table->string('on_conflict', 10)->default('keep')->after('replace');
        });
    }

    public function down(): void
    {
        Schema::table('uploads', function (Blueprint $table) {
            $table->dropConstrainedForeignId('api_token_id');
            $table->dropColumn(['api_token_name', 'on_conflict']);
        });
    }
};
