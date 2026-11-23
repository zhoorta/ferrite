<?php

namespace App\Support;

use Illuminate\Support\Str;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\Extension\HeadingPermalink\HeadingPermalinkExtension;
use League\CommonMark\MarkdownConverter;

/**
 * The public documentation pages at /docs, rendered from the Markdown files in docs/.
 *
 * Only the pages listed here are published: the others are design notes (encryption, UI polish).
 */
class Docs
{
    /** @var array<string, array{title: string, blurb: string, group: string}> slug => page, in menu order; the slug is the file name in docs/ */
    public const PAGES = [
        'install' => ['title' => 'Install and configure', 'blurb' => 'Docker or a standard Laravel install, web server, workers, reverse proxy, backups and upgrades.', 'group' => 'Installation and administration'],
        'storage-search-activity' => ['title' => 'Disks, search and activity', 'blurb' => 'Local, S3 and SFTP disks, name search and the activity log.', 'group' => 'Installation and administration'],
        'content-search' => ['title' => 'Search inside files', 'blurb' => 'What is indexed, and how it works on SQLite, MySQL and MariaDB.', 'group' => 'Installation and administration'],
        'storage-check' => ['title' => 'Storage check', 'blurb' => 'Verify that every blob is there and still has the right content.', 'group' => 'Installation and administration'],
        'users' => ['title' => 'Users and registration', 'blurb' => 'Accounts, quotas, registration policy and the trash.', 'group' => 'Installation and administration'],
        'security' => ['title' => 'Security', 'blurb' => 'What was reviewed, and the known limits.', 'group' => 'Installation and administration'],
        'public-site' => ['title' => 'Project page and demo mode', 'blurb' => 'How to run a public project page, downloads and a demo.', 'group' => 'Installation and administration'],
        'apps' => ['title' => 'Mac and Android apps', 'blurb' => 'Download, connect and use the native companions.', 'group' => 'Features'],
        'sharing' => ['title' => 'Sharing', 'blurb' => 'Share with users, links with password and expiry, upload links.', 'group' => 'Features'],
        'uploads' => ['title' => 'Uploads', 'blurb' => 'Chunked, resumable uploads and what happens when a name is taken.', 'group' => 'Features'],
        'versions' => ['title' => 'Versions', 'blurb' => 'Replacing a file keeps the earlier content; restore it from the web app.', 'group' => 'Features'],
        'themes' => ['title' => 'Themes', 'blurb' => 'The fifteen themes, and how to add one.', 'group' => 'Features'],
        'serving-files' => ['title' => 'How files are served', 'blurb' => 'Headers, inline types and why a file cannot attack the site.', 'group' => 'Features'],
        'api' => ['title' => 'API', 'blurb' => 'Tokens, browser approval for apps, and every endpoint under /api/v1.', 'group' => 'API'],
    ];

    public static function has(string $slug): bool
    {
        return array_key_exists($slug, self::PAGES);
    }

    /** @return array<string, list<array{slug: string, title: string, blurb: string}>> */
    public static function groups(): array
    {
        $groups = [];

        foreach (self::PAGES as $slug => $page) {
            $groups[$page['group']][] = ['slug' => $slug] + $page;
        }

        return $groups;
    }

    /** The page as HTML (our own files, raw HTML in them is dropped). */
    public static function render(string $slug): string
    {
        abort_unless(self::has($slug), 404);

        $environment = new Environment([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
            'heading_permalink' => ['insert' => 'none', 'apply_id_to_heading' => true, 'id_prefix' => ''],
        ]);
        $environment->addExtension(new CommonMarkCoreExtension);
        $environment->addExtension(new GithubFlavoredMarkdownExtension);
        $environment->addExtension(new HeadingPermalinkExtension);

        $markdown = file_get_contents(base_path("docs/{$slug}.md"));
        abort_if($markdown === false, 404);

        $html = (new MarkdownConverter($environment))->convert($markdown)->getContent();

        return self::rewriteLinks($html);
    }

    /** Links between docs stay on the site; other relative links point at the repository. */
    private static function rewriteLinks(string $html): string
    {
        $repo = rtrim((string) config('ferrite.repo_url'), '/');

        return preg_replace_callback('/href="([^"]*)"/', function (array $m) use ($repo) {
            $href = html_entity_decode($m[1]);

            if ($href === '' || preg_match('#^([a-z][a-z0-9+.-]*:|//|\#)#i', $href)) {
                return $m[0];
            }

            [$path, $anchor] = array_pad(explode('#', $href, 2), 2, null);
            $anchor = $anchor === null ? '' : '#'.$anchor;

            if (preg_match('/^([a-z0-9-]+)\.md$/', $path, $mm) && self::has($mm[1])) {
                return 'href="'.e(route('docs.show', $mm[1]).$anchor).'"';
            }

            $target = Str::startsWith($path, '../') ? substr($path, 3) : 'docs/'.$path;

            return 'href="'.e($repo.'/blob/main/'.$target.$anchor).'"';
        }, $html);
    }
}
