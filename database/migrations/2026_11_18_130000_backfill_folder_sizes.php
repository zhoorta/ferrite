<?php

use App\Support\FolderSizes;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Folders have always had a `size` column, left at 0. From now on it holds the total of the files inside, kept up
     * to date by the actions; this fills it in once for what already exists.
     */
    public function up(): void
    {
        app(FolderSizes::class)->recount();
    }

    public function down(): void {}
};
