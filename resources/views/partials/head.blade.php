<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta name="csrf-token" content="{{ csrf_token() }}" />

<title>
    {{ filled($title ?? null) ? $title.' - '.config('app.name', 'Laravel') : config('app.name', 'Laravel') }}
</title>

<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">

@fonts

@vite(['resources/css/app.css', 'resources/js/app.js'])
<script>
    // Signed-in pages carry the account's theme from the server; remember it for guest pages (sign-in, share links).
    try {
        const root = document.documentElement;
        if (root.dataset.palette) localStorage.setItem('ferrite.palette', root.dataset.palette);
        else root.dataset.palette = localStorage.getItem('ferrite.palette') || 'ferrite';
        root.classList.toggle('dark', !{{ \Illuminate\Support\Js::from(\App\Models\User::LIGHT_PALETTES) }}.includes(root.dataset.palette));
    } catch (e) {}
</script>
