<?php

namespace App\Console\Commands;

use App\Jobs\Imports\ImportStage;
use App\Jobs\Imports\SuggestCategoriesForChunk;
use Illuminate\Console\Command;
use Illuminate\Console\Prohibitable;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Rebuilds the demo from scratch: fresh database and demo data, no leftover
 * uploaded statements, and empty queues. Handy between screen-recording takes.
 */
#[AsCommand(name: 'demo:reset')]
class ResetDemo extends Command
{
    use Prohibitable;

    protected $signature = 'demo:reset {--force : Skip the confirmation prompt}';

    protected $description = 'Wipe the database and uploaded statements, then reseed the demo';

    public function handle(): int
    {
        if ($this->isProhibited() || $this->laravel->isProduction()) {
            $this->components->error('demo:reset is not available in production.');

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->components->confirm('This deletes all data and uploaded statements. Continue?')) {
            return self::SUCCESS;
        }

        Storage::disk('local')->deleteDirectory('imports');

        foreach ([ImportStage::QUEUE, SuggestCategoriesForChunk::QUEUE] as $queue) {
            $this->callSilently('queue:clear', ['--queue' => $queue, '--force' => true]);
        }

        $this->call('migrate:fresh', ['--seed' => true, '--force' => true]);

        $this->components->info('Demo reset. Log in as demo@example.com / password.');

        return self::SUCCESS;
    }
}
