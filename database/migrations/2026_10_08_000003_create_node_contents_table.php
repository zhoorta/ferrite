<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Extracted text of file contents, one row per distinct content (sha256), for content search.
 * The index itself depends on the database: an FTS5 table kept in step by triggers on SQLite,
 * a FULLTEXT index on MySQL and MariaDB. Other databases keep the table but have no search.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('node_contents', function (Blueprint $table) {
            $table->id();
            $table->char('sha256', 64)->unique();
            // indexed (text is set), skipped (binary, too big, unsupported) or failed (extraction broke).
            $table->string('status', 10)->index();
            $table->string('reason', 100)->nullable();
            $table->longText('text')->nullable();
            $table->timestamp('extracted_at')->nullable();
        });

        match (DB::connection()->getDriverName()) {
            'sqlite' => $this->createSqliteIndex(),
            'mysql', 'mariadb' => Schema::table('node_contents', fn (Blueprint $table) => $table->fullText('text')),
            default => null,
        };
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::unprepared('drop table if exists node_contents_fts');
        }

        Schema::dropIfExists('node_contents');
    }

    /**
     * External-content FTS5 table over node_contents.text: it stores only the index, and the
     * triggers keep it in step with inserts, updates and deletes. Accents are ignored in matches.
     */
    private function createSqliteIndex(): void
    {
        DB::unprepared("create virtual table node_contents_fts using fts5(text, content='node_contents', content_rowid='id', tokenize='unicode61 remove_diacritics 2')");

        DB::unprepared("create trigger node_contents_ai after insert on node_contents begin
            insert into node_contents_fts(rowid, text) values (new.id, coalesce(new.text, ''));
        end");

        DB::unprepared("create trigger node_contents_ad after delete on node_contents begin
            insert into node_contents_fts(node_contents_fts, rowid, text) values ('delete', old.id, coalesce(old.text, ''));
        end");

        DB::unprepared("create trigger node_contents_au after update on node_contents begin
            insert into node_contents_fts(node_contents_fts, rowid, text) values ('delete', old.id, coalesce(old.text, ''));
            insert into node_contents_fts(rowid, text) values (new.id, coalesce(new.text, ''));
        end");
    }
};
