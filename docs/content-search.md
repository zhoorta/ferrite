# Content search

The search page finds words inside files as well as names. A name match is shown first; below it, "Found inside files" lists other files with a snippet around the match.

## What gets indexed

- **Text files**: anything with a `text/*` type or a known text type (JSON, XML, YAML, shell, SQL and similar), plus files with a generic type but a text extension (`.md`, `.csv`, `.log`, `.php`, `.js` and so on). Only the first `FERRITE_SEARCH_MAX_TEXT_KB` (2048) are read. A file that is binary (a NUL byte) or not valid UTF-8 is skipped.
- **PDFs** with a text layer, through `pdftotext` (poppler-utils, in the Docker image; install it on a standard install). A PDF over `FERRITE_SEARCH_MAX_PDF_MB` (50) is skipped, and so is one `pdftotext` cannot read (encrypted, damaged).
- **Not indexed**: scanned documents and images (no OCR), Office files (DOCX, XLSX are zip and XML, possible later), archives, audio and video.

Whitespace is collapsed, so a snippet reads as one line.

## How it works

1. When an upload finishes, an `ExtractContent` job goes on the `search` queue. The default queue worker serves `search` after `default` (`queue:work --queue=default,search`), so it never delays other jobs; there is no extra worker. On a remote disk the PDF is copied to the temp folder first.
2. The text goes into `node_contents`, **one row per distinct content (sha256)**: identical files are read once. A row has a status: `indexed`, `skipped` (with a reason) or `failed`.
3. The index depends on the database, behind one interface (`App\Support\Search\ContentSearchDriver`):
   - **SQLite**: an FTS5 table (`node_contents_fts`, external content, kept in step by triggers). Ranking with `bm25`, snippets with `snippet()`. Accents are ignored (`reuniao` finds `reunião`).
   - **MySQL and MariaDB**: an InnoDB `FULLTEXT` index on `node_contents.text`, boolean mode. There is no snippet function, so a window of text is cut in SQL and the words are marked in PHP.
   - Other databases keep the table but have no content search; the search page then behaves as before.
4. A search turns the words you type into plain words (letters and digits only, so nothing is read as search syntax). **Every word must be present, and each matches the start of a word** (`renov budget` finds "renovation budget").
5. The query joins the index to `nodes` through the sha256 and applies the same visibility rules as the name search: your own files and everything below folders shared with you; trashed files and files inside trashed folders are left out. The text is never looked up for a node you cannot see.

## Differences between databases

- MySQL and MariaDB ignore words shorter than three characters and a short stopword list (`the`, `with`, `from` …). Those words are dropped from the query, so a search made only of them finds nothing in contents. SQLite has neither limit.
- Results can rank differently: `bm25` on SQLite, InnoDB relevance on MySQL.
- Accents are ignored on MySQL and MariaDB too, as long as the table keeps Laravel's default collation (`utf8mb4_unicode_ci`). A case- and accent-sensitive collation (`_bin`, `_as_cs`) makes `reuniao` stop matching `reunião`.
- The tests pass on MySQL 8.4 and 9 and on MariaDB 11. CI runs MySQL 8.4.

## Running it

- `FERRITE_SEARCH_CONTENTS=false` turns indexing and content results off. Names stay searchable. Existing rows stay until `search:prune` finds nothing uses them; truncate `node_contents` to remove them at once.
- `php artisan search:index` queues extraction for every stored file that has no row yet (run it once after upgrading from a version without content search). `--retry` also clears failed rows first, for example after installing `pdftotext`.
- `php artisan search:prune` (daily, from the scheduler) deletes rows no file uses any more.
- Without a queue worker (`QUEUE_CONNECTION=sync`) extraction runs inside the upload request's job, which is fine for small files.

## Security

The extracted text lives in the database, unencrypted, whoever may read the file (see `docs/security.md`). Snippets are escaped before they are shown, with the match marked in `<mark>`.

## Tests

`tests/Feature/Search/ContentSearchTest.php` covers extraction, the permission rules and the housekeeping commands. It runs on SQLite by default. To run it on MySQL or MariaDB, point the suite at an empty test database (never your real one; the tests empty its tables):

```sh
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_DATABASE=ferrite_test DB_USERNAME=root DB_PASSWORD= \
  php artisan test --compact tests/Feature/Search/ContentSearchTest.php
```

CI does this against a MySQL service in the `mysql` job. A PDF test is skipped when `pdftotext` is missing.
