<?php

namespace App\Console\Commands;

use App\Actions\Nodes\PurgeNode;
use App\Models\Node;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('trash:purge')]
#[Description('Permanently delete items that have been in the trash longer than the retention period')]
class PurgeTrash extends Command
{
    public function handle(PurgeNode $purge): int
    {
        $count = 0;

        Node::query()
            ->where('trashed_at', '<', now()->subDays(config('shed.trash_days')))
            ->pluck('id')
            ->each(function (int $id) use ($purge, &$count) {
                // Deleting a folder removes what is inside it, which may include later ids.
                if (($node = Node::query()->find($id)) !== null) {
                    $purge->purge($node);
                    $count++;
                }
            });

        $this->info("Permanently deleted {$count} item(s) from the trash.");

        return self::SUCCESS;
    }
}
