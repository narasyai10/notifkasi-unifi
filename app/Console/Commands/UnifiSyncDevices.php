<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use App\Services\UnifiDeviceSyncService;

class UnifiSyncDevices extends Command
{
    /**
     * Nama command
     */
    protected $signature = 'unifi:sync-devices';

    /**
     * Deskripsi command
     */
    protected $description = 'CRON: Sinkronisasi UniFi devices (Cloud + Local)';

    /**
     * Execute command
     */
    public function handle(): int
    {
        $this->info('🕒 UniFi Device Sync dimulai');
        Log::channel('sync_log')->info('[COMMAND] unifi:sync-devices started');

        /** @var UnifiDeviceSyncService $service */
        $service = app(UnifiDeviceSyncService::class);

        // =========================
        // FETCH DEVICES (CLOUD + LOCAL)
        // =========================
        $cloudDevices = [];
        $localDevices = [];

        try {
            $cloudDevices = $service->fetchDevicesFromCloud();
        } catch (\Throwable $e) {
            Log::channel('sync_log')->error('[COMMAND] Fetch cloud devices failed', [
                'error' => $e->getMessage(),
            ]);
        }

        try {
            $localDevices = $service->fetchDevicesFromLocal();
        } catch (\Throwable $e) {
            Log::channel('sync_log')->error('[COMMAND] Fetch local devices failed', [
                'error' => $e->getMessage(),
            ]);
        }

        // =========================
        // MERGE DEVICES
        // =========================
        $devices = array_merge($cloudDevices, $localDevices);

        if (empty($devices)) {
            $this->warn('No device found (cloud & local)');
            Log::channel('sync_log')->warning('[COMMAND] No device found (cloud & local)');
            return Command::SUCCESS;
        }

        // =========================
        // PROGRESS BAR
        // =========================
        $bar = $this->output->createProgressBar(count($devices));
        $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%%');
        $bar->start();

        foreach ($devices as $device) {
            $service->syncDevicePipeline($device);
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();

        $this->info('✅ UniFi Device Sync selesai');
        Log::channel('sync_log')->info('[COMMAND] unifi:sync-devices finished', [
            'cloud_device' => count($cloudDevices),
            'local_device' => count($localDevices),
            'total_device' => count($devices),
        ]);

        return Command::SUCCESS;
    }
}