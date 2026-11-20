<?php

namespace App\Http\Controllers;

use App\Support\Downloads;
use Illuminate\Contracts\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DownloadController extends Controller
{
    public function index(): View
    {
        return view('downloads', ['apps' => Downloads::all()]);
    }

    public function file(string $file): BinaryFileResponse
    {
        $item = Downloads::find($file);
        abort_if($item === null, 404);

        $platform = collect(Downloads::PLATFORMS)->first(fn ($p) => in_array(pathinfo($file, PATHINFO_EXTENSION), $p['extensions'], true));

        return response()->download($item['path'], $file, [
            'Content-Type' => $platform['mime'],
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
