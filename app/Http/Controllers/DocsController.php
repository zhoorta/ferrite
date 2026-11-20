<?php

namespace App\Http\Controllers;

use App\Support\Docs;
use Illuminate\Contracts\View\View;

class DocsController extends Controller
{
    public function index(): View
    {
        return view('docs.index', ['groups' => Docs::groups()]);
    }

    public function show(string $page): View
    {
        abort_unless(Docs::has($page), 404);

        return view('docs.show', ['slug' => $page, 'page' => Docs::PAGES[$page], 'groups' => Docs::groups(), 'html' => Docs::render($page)]);
    }
}
