<?php

use App\Support\FolderSizes;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Folders have always had a `size` column, left at 0. From now on it holds the bytes of the files inside, and the
     * new `files_count` how many there are, both kept up to date by the actions (docs/folder-sizes.md). Existing
     * folders are filled in once here.
     */
    public function up(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->unsignedInteger('files_count')->default(0)->after('size');
        });

        app(FolderSizes::class)->recount();
    }

    public function down(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->dropColumn('files_count');
        });
    }
};
