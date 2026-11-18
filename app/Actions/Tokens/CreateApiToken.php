<?php

namespace App\Actions\Tokens;

use App\Models\ApiToken;
use App\Models\User;
use App\Support\ApiAccess;
use InvalidArgumentException;

class CreateApiToken
{
    /**
     * Make a token for $user: scoped to one folder (null: the whole drive), with the abilities of an access
     * level from `ApiAccess::LEVELS`. Callers validate the choices; the folder must belong to the user.
     *
     * @return array{ApiToken, string} the token and its clear text, which exists only now
     */
    public function handle(User $user, string $name, ?int $folderId, string $access, ?int $days): array
    {
        $abilities = ApiAccess::LEVELS[$access] ?? throw new InvalidArgumentException("Unknown access level [{$access}].");

        $created = $user->createToken($name, $abilities, $days === null ? null : now()->addDays($days));
        /** @var ApiToken $token */
        $token = $created->accessToken;
        $token->forceFill(['folder_id' => $folderId])->save();

        return [$token, $created->plainTextToken];
    }
}
