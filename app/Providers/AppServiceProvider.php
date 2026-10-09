<?php

namespace App\Providers;

use App\Models\ApiToken;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\DevCommands;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureTrustedProxies();

        Sanctum::usePersonalAccessTokenModel(ApiToken::class);
        $perToken = fn (int $max) => fn (Request $request) => Limit::perMinute($max)->by($request->user()?->currentAccessToken()?->id ?? $request->ip());
        RateLimiter::for('api-content', $perToken(1200));
        RateLimiter::for('api-upload-start', $perToken(120));
        RateLimiter::for('api-upload', $perToken(1200));
        RateLimiter::for('api-read', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->currentAccessToken()?->id ?? $request->ip()));

        Gate::define('admin', fn (User $user) => $user->isAdmin());

        // `composer dev` only listens on the default queue; uploads are stored from their own one.
        if ($this->app->runningInConsole()) {
            DevCommands::artisan('queue:listen uploads --queue=uploads --tries=1 --timeout=0', 'uploads');
        }
    }

    /**
     * Believe the forwarding headers of the proxies listed in FERRITE's TRUSTED_PROXIES setting. Done
     * here rather than in bootstrap/app.php because config is not available there.
     */
    protected function configureTrustedProxies(): void
    {
        $proxies = config('ferrite.trusted_proxies');

        if (! is_string($proxies) || trim($proxies) === '') {
            return;
        }

        TrustProxies::at($proxies === '*' ? '*' : array_map('trim', explode(',', $proxies)));
        TrustProxies::withHeaders(
            Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_HOST | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO,
        );
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        // In production every generated URL (share links, password reset mails) comes from APP_URL,
        // never from the Host header a visitor sent.
        if (app()->isProduction() && filled(config('app.url'))) {
            URL::forceRootUrl(config('app.url'));
            URL::forceScheme(parse_url((string) config('app.url'), PHP_URL_SCHEME) ?: 'https');
        }

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
