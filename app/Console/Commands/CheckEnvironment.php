<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CheckEnvironment extends Command
{
    protected $signature = 'app:check-environment
                            {--write : Write a new persistence verification token}
                            {--expect= : Verify that the stored token matches this value}';

    protected $description = 'Verify application database storage and persistence across restarts';

    public function handle(): int
    {
        if ($this->option('write') && $this->option('expect') !== null) {
            $this->error('Use --write and --expect separately.');

            return self::INVALID;
        }

        if ($this->option('write')) {
            DB::table('environment_checks')->updateOrInsert(
                ['name' => 'persistence_probe'],
                ['value' => (string) Str::uuid(), 'updated_at' => now()],
            );
        }

        $record = DB::table('environment_checks')->where('name', 'persistence_probe')->first();

        if (! $record) {
            $this->error('No verification record. Run with --write first.');

            return self::FAILURE;
        }

        if ($this->option('expect') !== null && ! hash_equals($record->value, (string) $this->option('expect'))) {
            $this->error('Persistence verification failed: token mismatch.');

            return self::FAILURE;
        }

        $this->line(json_encode([
            'status' => 'ok',
            'token' => $record->value,
            'stored_at' => $record->updated_at,
        ], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
