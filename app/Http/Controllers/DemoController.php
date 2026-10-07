<?php

namespace App\Http\Controllers;

use App\Support\Demo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

class DemoController extends Controller
{
    public function __invoke(Demo $demo): RedirectResponse
    {
        abort_unless(Demo::enabled(), 404);

        if ($demo->full()) {
            return redirect()->route('login')->withErrors(['demo' => __('The demo is busy right now. Please try again in a few minutes.')]);
        }

        Auth::login($demo->createVisitor());
        request()->session()->regenerate();

        return redirect()->route('files');
    }
}
