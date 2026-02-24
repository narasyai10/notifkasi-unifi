<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use GuzzleHttp\Cookie\CookieJar;

use App\Models\Gesit\InternetStatus;
use App\Models\Gesit\WanFailoverHistory;
use App\Mail\SendUnifiMail;

class UnifiWanSyncService
{
    private ?CookieJar $unifiCookieJar = null;

    /* =====================================================
     * ENTRY POINT
     * ===================================================== */
    public function run(): void
    {
        Log::channel('sync_log')->info('[WAN] Sync started');

        try {
            $mailData = null;

            DB::transaction(function () use (&$mailData) {
                $mailData = $this->syncWan();
            });

            if ($mailData) {
                $this->sendUnifiMail($mailData);
            }

        } catch (\Throwable $e) {
            Log::channel('sync_log')->error('[WAN] Sync error', [
                'error' => $e->getMessage(),
            ]);
        }

        Log::channel('sync_log')->info('[WAN] Sync finished');
    }

    /* =====================================================
     * LOGIN UNIFI
     * ===================================================== */
    private function unifiLocalLogin(): void
    {
        if ($this->unifiCookieJar !== null) {
            return;
        }

        $this->unifiCookieJar = new CookieJar();

        $response = Http::withOptions([
            'cookies' => $this->unifiCookieJar,
            'verify'  => false,
            'timeout' => 10,
        ])->post(env('UNIFI_2_BASE_URL') . '/api/login', [
            'username' => env('UNIFI_USERNAME'),
            'password' => env('UNIFI_PASSWORD'),
            'remember' => true,
        ]);

        if (! $response->successful()) {
            throw new \Exception('UniFi login failed');
        }
    }

    /* =====================================================
     * FETCH WAN DEVICE
     * ===================================================== */
    protected function syncWan(): ?array
    {
        $this->unifiLocalLogin();

        $response = Http::withOptions([
            'cookies' => $this->unifiCookieJar,
            'verify'  => false,
            'timeout' => 10,
        ])->get(env('UNIFI_2_BASE_URL') . '/api/s/default/stat/device');

        if (! $response->successful()) {
            throw new \Exception('Failed fetch WAN device');
        }

        $device = collect($response->json('data', []))
            ->firstWhere('type', 'ugw');

        if (! $device) {
            Log::channel('sync_log')->warning('[WAN] USG not found');
            return null;
        }

        return $this->processWanFailover($device);
    }

    /* =====================================================
     * PROCESS WAN FAILOVER
     * ===================================================== */
    protected function processWanFailover(array $device): ?array
    {
        $deviceId = $device['device_id'] ?? $device['_id'];
        $siteId   = $device['site_id'] ?? null;

        $wan1 = $device['wan1'] ?? [];
        $wan2 = $device['wan2'] ?? [];

        // =========================
        // IP-BASED STATUS
        // =========================
        $wan1Ip = $wan1['ip'] ?? null;
        $wan2Ip = $wan2['ip'] ?? null;

        $wan1Up = !empty($wan1Ip) && $wan1Ip !== '0.0.0.0';
        $wan2Up = !empty($wan2Ip) && $wan2Ip !== '0.0.0.0';

        $wan1Status = $wan1Up ? 'online' : 'offline';
        $wan2Status = $wan2Up ? 'online' : 'offline';

        // =========================
        // ROLE
        // =========================
        $wan1Role = 'primary';
        $wan2Role = 'backup';

        // =========================
        // STATE DETECTION
        // =========================
        if ($wan1Up) {
            $state = 'primary';
            $activeWan = 'wan_1';
        } elseif ($wan2Up) {
            $state = 'failover';
            $activeWan = 'wan_2';
        } else {
            $state = 'down';
            $activeWan = null;
        }

        Log::channel('sync_log')->info('[WAN EVAL]', [
            'wan1_ip' => $wan1Ip,
            'wan2_ip' => $wan2Ip,
            'state'   => $state,
        ]);

        // =========================
        // UPDATE CURRENT STATUS
        // =========================
        $status = InternetStatus::updateOrCreate(
            [
                'device_id' => $deviceId,
                'site_id'   => $siteId,
            ],
            [
                'state'        => $state,
                'active_wan'   => $activeWan,
                'wan1_role'    => $wan1Role,
                'wan2_role'    => $wan2Role,
                'wan1_status'  => $wan1Status,
                'wan2_status'  => $wan2Status,
                'wan1_ip'      => $wan1Ip,
                'wan2_ip'      => $wan2Ip,
                'image'        => 'https://gesit.sidoarjokab.go.id/assets/images/unifi/unifi_default.png',
                'checked_at'   => now(),
            ]
        );

        // =========================
        // LAST EVENT
        // =========================
        $last = WanFailoverHistory::where('device_id', $deviceId)
            ->latest('created_at')
            ->first();

        // NORMAL STARTUP
        if ($wan1Up && $wan2Up && !$last) {
            return null;
        }

        if ($last && $last->state === $state) {
            return null;
        }

        // =========================
        // TIMESTAMP
        // =========================
        $failoverAt = $last?->failover_at;
        $restoredAt = $last?->restored_at;

        if ($state === 'failover') {
            $failoverAt = now();
            $restoredAt = null;
        }

        if ($state === 'down') {
            $failoverAt = null;
            $restoredAt = null;
        }

        if ($state === 'primary' && $last && in_array($last->state, ['failover', 'down'])) {
            $restoredAt = now();
        }

        // =========================
        // CREATE HISTORY
        // =========================
        WanFailoverHistory::create([
            'device_id'   => $deviceId,
            'state'       => $state,
            'active_wan'  => $activeWan,
            'wan1_ip'     => $wan1Ip,
            'wan2_ip'     => $wan2Ip,
            'failover_at' => $failoverAt,
            'restored_at' => $restoredAt,
            'created_at'  => now(),
        ]);

        $status->update([
            'failover_at' => $failoverAt,
            'restored_at' => $restoredAt,
        ]);

        // =========================
        // EMAIL PAYLOAD
        // =========================
        return [
            'subjek' => match ($state) {
                'failover' => 'Notifikasi Internet Failover',
                'primary'  => 'Notifikasi Internet Restored',
                'down'     => 'Notifikasi Internet Down',
            },
            'icon' => match ($state) {
                'failover' => '🟠',
                'primary'  => '🟢',
                'down'     => '🔴',
            },
            'title' => match ($state) {
                'failover' => 'Internet Failover',
                'primary'  => 'Internet Restored',
                'down'     => 'Internet Down',
            },
            'description' => match ($state) {
                'failover' => 'Koneksi internet beralih ke WAN cadangan.',
                'primary'  => 'Koneksi internet utama telah kembali normal.',
                'down'     => 'Semua koneksi internet terputus.',
            },
            'device_name' => 'USG 3P - ROUTER',
            'site_name'   => 'UKPBJ - USG',
            'time'        => now()->format('d M Y H:i'),
            'dashboard_url' => env('UNIFI_DASHBOARD_URL'),
            'image'        => 'https://gesit.sidoarjokab.go.id/assets/images/unifi/unifi_default.png',
            'detail' => [
                'WAN1' => 'Primary ' . ucfirst($wan1Status),
                'WAN2' => 'Backup ' . ucfirst($wan2Status),
            ],
        ];
    }

    /* =====================================================
     * SEND EMAIL
     * ===================================================== */
    protected function sendUnifiMail(array $data): void
    {
        try {
            Mail::to(explode(',', env('UNIFI_NOTIFY_EMAIL')))
                ->send(new SendUnifiMail($data));

            Log::channel('sync_log')->info('[MAIL] WAN email sent', [
                'subject' => $data['subjek'],
            ]);

        } catch (\Throwable $e) {
            Log::channel('sync_log')->error('[MAIL] WAN email failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}