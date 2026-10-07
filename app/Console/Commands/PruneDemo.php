<?php

namespace App\Console\Commands;

use App\Support\Demo;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('demo:prune')]
#[Description('Delete demo accounts past their lifetime, together with their files')]
class PruneDemo extends Command
{
    public function handle(Demo $demo): int
    {
        $this->info('Deleted '.$demo->prune().' demo account(s).');

        return self::SUCCESS;
    }
}
