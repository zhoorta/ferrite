<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use App\Support\StorageUsage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UsageController extends Controller
{
    private const LIMIT = 20;

    /**
     * Where the space goes inside the token's folder (the whole drive for a whole-drive token): by kind of file, the
     * biggest folders and files, the trash and the earlier versions. The numbers are the web Usage page's
     * (`StorageUsage`), limited to what the token reaches; the quota is the account's.
     */
    public function show(Request $request, StorageUsage $usage): JsonResponse
    {
        $user = $request->user();
        /** @var ApiToken $token */
        $token = $user->currentAccessToken();
        $root = $token->rootFolder();

        $numbers = $usage->forUser($user, $root, self::LIMIT);

        return response()->json([
            'scope' => [
                'name' => $root->name ?? 'Whole drive',
                'files' => $numbers['files'],
                'bytes' => $numbers['active'],
                'loose' => $numbers['loose'],
                'trash' => $numbers['trash'],
                'versions' => $numbers['versions'],
                'saved' => $numbers['saved'],
            ],
            'quota' => ['used' => $numbers['used'], 'limit' => $numbers['quota']],
            'kinds' => $numbers['kinds'],
            'folders' => array_map(fn (array $folder) => ['id' => $folder['id'], 'name' => $folder['name'], 'path' => $folder['name'], 'bytes' => $folder['bytes']], $numbers['folders']),
            'largest_folders' => $numbers['largest_folders'],
            'largest_files' => array_map(fn (array $file) => ['id' => $file['id'], 'name' => $file['name'], 'path' => $file['path'], 'size' => $file['size'], 'mime' => $file['mime']], $numbers['largest']),
        ]);
    }
}
