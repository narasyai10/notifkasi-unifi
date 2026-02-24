<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use App\Services\UnifiWanSyncService;

class UnifiSyncWanFailoverStatus extends Command
{
    protected $signature = 'unifi:sync-wan';
    protected $description = 'Sync UniFi WAN failover status';

    public function handle(): int
    {
        Log::channel('sync_log')->info('[COMMAND] unifi:sync-wan started');

        $isInteractive = $this->input->isInteractive();

        if ($isInteractive) {
            $this->info('🕒 UniFi WAN Sync dimulai');
            $bar = $this->output->createProgressBar(2);
            $bar->setFormat(' %current%/%max% [%bar%] %message%');
            $bar->start();

            $bar->setMessage('Mengambil status WAN...');
        }

        try {
            app(UnifiWanSyncService::class)->run();
        } catch (\Throwable $e) {
            Log::channel('sync_log')->error('[COMMAND] unifi:sync-wan failed', [
                'error' => $e->getMessage(),
            ]);

            return Command::FAILURE;
        }

        if ($isInteractive) {
            $bar->advance();
            $bar->setMessage('Memproses status WAN');
            $bar->advance();
            $bar->finish();
            $this->newLine();
            $this->info('✅ UniFi WAN Sync selesai');
        }

        Log::channel('sync_log')->info('[COMMAND] unifi:sync-wan finished');

        return Command::SUCCESS;
    }
}
