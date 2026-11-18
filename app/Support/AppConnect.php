<?php

namespace App\Support;

use App\Actions\Tokens\CreateApiToken;
use App\Models\ApiToken;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Browser approval for the native apps (docs/api.md, "Connecting an app"). The app sends the user to `/connect`
 * with a PKCE challenge; once the user approves, a one-time code goes back to the app, which swaps it for a token
 * together with the verifier. Only the approved choices wait in the cache for two minutes: the token itself is
 * made at the swap, so there is no secret to leak from the cache, and a code is useless without the verifier.
 */
class AppConnect
{
    private const TTL_SECONDS = 120;

    /**
     * Check what an app asks for. Returns the cleaned request, or null when it is not acceptable: the page then
     * refuses to continue instead of sending anything to an address that is not allowed.
     *
     * @param  array<string, mixed>  $input
     * @return array{client: string, device: string, redirect: string, challenge: string, state: string}|null
     */
    public function request(array $input): ?array
    {
        $client = trim((string) ($input['client'] ?? ''));
        $device = trim((string) ($input['device'] ?? ''));
        $redirect = (string) ($input['redirect'] ?? '');
        $challenge = (string) ($input['challenge'] ?? '');
        $state = (string) ($input['state'] ?? '');

        $valid = $client !== '' && mb_strlen($client) <= 40
            && mb_strlen($device) <= 40
            && in_array($redirect, config('ferrite.connect_redirects'), true)
            && preg_match('/^[A-Za-z0-9_-]{43}$/', $challenge) === 1
            && preg_match('/^[A-Za-z0-9._~-]{0,200}$/', $state) === 1
            && ! preg_match('/[\x00-\x1f\x7f]/u', $client.$device);

        return $valid ? compact('client', 'device', 'redirect', 'challenge', 'state') : null;
    }

    /**
     * Remember an approval and return the one-time code for the app.
     *
     * @param  array{client: string, device: string, challenge: string}  $request
     */
    public function approve(User $user, array $request, ?int $folderId, string $access, ?int $days): string
    {
        $code = Str::random(48);

        Cache::put($this->key($code), [
            'user' => $user->id,
            'name' => Str::limit($request['device'] === '' ? $request['client'] : "{$request['client']} ({$request['device']})", 100, ''),
            'folder' => $folderId,
            'access' => $access,
            'days' => $days,
            'challenge' => $request['challenge'],
        ], self::TTL_SECONDS);

        return $code;
    }

    /**
     * Swap a code and its verifier for a token. The code works once, whatever the outcome, so a guessed
     * verifier cannot be retried. Returns null when anything does not match or the user is gone or disabled.
     *
     * @return array{ApiToken, string}|null the token and its clear text
     */
    public function redeem(string $code, string $verifier): ?array
    {
        $grant = Cache::pull($this->key($code));

        if (! is_array($grant) || ! hash_equals($grant['challenge'], self::challengeFor($verifier))) {
            return null;
        }

        $user = User::query()->find((int) $grant['user']);

        if ($user === null || $user->isDisabled()) {
            return null;
        }

        return app(CreateApiToken::class)->handle($user, $grant['name'], $grant['folder'], $grant['access'], $grant['days']);
    }

    /** The S256 challenge for a verifier: base64url of its SHA-256, without padding. */
    public static function challengeFor(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    private function key(string $code): string
    {
        return 'app-connect:'.hash('sha256', $code);
    }
}
