<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Carbon\Carbon;
use GuzzleHttp\Cookie\CookieJar;

use App\Models\Device;
use App\Models\DeviceHistory;
use App\Mail\SendUnifiMail;

class UnifiDeviceSyncService
{
    /* =====================================================
     * PROPERTY
     * ===================================================== */
    protected ?CookieJar $unifiCookieJar = null;

    protected string $localBaseUrl;
    protected string $localUsername;
    protected string $localPassword;

    /* =====================================================
     * CONSTRUCTOR
     * ===================================================== */
    public function __construct()
    {
        $this->localBaseUrl   = rtrim(env('UNIFI_2_BASE_URL'), '/');
        $this->localUsername = env('UNIFI_USERNAME');
        $this->localPassword = env('UNIFI_PASSWORD');
    }

    /* =====================================================
     * ENTRY POINT (CLOUD + LOCAL)
     * ===================================================== */
    public function run(): void
    {
        Log::channel('sync_log')->info('[DEVICE] Sync started (CLOUD + LOCAL)');

        // ===============================
        // CLOUD
        // ===============================
        foreach ($this->fetchDevicesFromCloud() as $device) {
            $this->syncDevicePipeline($device);
        }

        // ===============================
        // LOCAL
        // ===============================
        $localDevices = $this->fetchDevicesFromLocal();

        Log::channel('sync_log')->info('[DEVICE] Local devices found', [
            'count' => count($localDevices),
        ]);

        foreach ($localDevices as $device) {
            $this->syncDevicePipeline($device);
        }

        Log::channel('sync_log')->info('[DEVICE] Sync finished');
    }

    /* =====================================================
     * PIPELINE
     * ===================================================== */
    public function syncDevicePipeline(array $device): void
    {
        $mailQueue = [];

        try {
            DB::transaction(function () use ($device, &$mailQueue) {
                $mailQueue = $this->syncDevice($device);
            });

            foreach ($mailQueue as $mail) {
                $this->sendUnifiMail($mail);
            }

        } catch (\Throwable $e) {
            Log::channel('sync_log')->error('[DEVICE] Sync device error', [
                'device_id' => $device['id'] ?? null,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    /* =====================================================
     * FETCH DEVICES - CLOUD
     * ===================================================== */
    public function fetchDevicesFromCloud(): array
    {
        $response = Http::withHeaders([
            'X-API-KEY' => env('UNIFI_1_API_KEY'),
            'Accept'   => 'application/json',
        ])
        ->timeout(15)
        ->get(env('UNIFI_1_BASE_URL') . '/v1/devices');

        if (! $response->successful()) {
            Log::channel('sync_log')->error('[DEVICE] Failed fetch UniFi CLOUD devices');
            return [];
        }

        return collect($response->json('data', []))
            ->flatMap(function ($host) {
                return collect($host['devices'] ?? [])
                    ->map(function ($device) use ($host) {
                        $device['hostId']   = $host['hostId']   ?? 'cloud';
                        $device['hostName'] = $host['hostName'] ?? 'UniFi Cloud';
                        return $device;
                    });
            })
            ->filter(fn ($d) => ! empty($d['id']))
            ->values()
            ->toArray();
    }

    /* =====================================================
     * LOGIN UNIFI LOCAL
     * ===================================================== */
    private function unifiLocalLogin(): void
    {
        if ($this->unifiCookieJar !== null) {
            return;
        }

        $this->unifiCookieJar = new \GuzzleHttp\Cookie\CookieJar();

        $response = Http::withOptions([
                'cookies' => $this->unifiCookieJar,
                'verify'  => false, // SSL self-signed
                'timeout' => 10,
            ])
            ->post("{$this->localBaseUrl}/api/login", [
                'username' => $this->localUsername,
                'password' => $this->localPassword,
                'remember' => true,
            ]);

        if (! $response->successful()) {
            throw new \Exception(
                'UniFi login failed: ' .
                $response->status() . ' ' .
                substr($response->body(), 0, 300)
            );
        }
    }


    /* =====================================================
     * FETCH DEVICES LOCAL
     * ===================================================== */
    public function fetchDevicesFromLocal(): array
    {
        $this->unifiLocalLogin();
        $response = Http::withOptions([
            'cookies' => $this->unifiCookieJar,
            'verify'  => false,
            'timeout' => 15,
        ])->get("{$this->localBaseUrl}/api/s/default/stat/device");
        if (! $response->successful()) {
            Log::channel('sync_log')->error('[DEVICE] Failed fetch UniFi LOCAL devices');
            return [];
        }
        return collect($response->json('data', []))
            ->filter(fn ($d) => ! empty($d['mac']))
            ->map(function ($d) {
                return [
                    'id'        => $d['device_id'] ?? null,
                    'name'      => $d['name'] ?? $d['model'],
                    'model'     => $d['model'] ?? null,
                    'ip'        => $d['ip'] ?? null,
                    'mac'       => $d['mac'] ?? null,
                    'status'    => ((int)($d['state'] ?? 0) === 1 ? 'online' : 'offline'),
                    'uptime'    => (int) ($d['uptime'] ?? 0),
                    'hostId'    => $d['site_id'] ?? '6968d6ccf084835f54bb33b1',
                    'hostName'  => env('UNIFI_SITE_NAME', 'UKPBJ - USG'),
                ];
            })
            ->values()
            ->toArray();
    }

    /* =====================================================
     * SYNC DEVICE
     * ===================================================== */
    protected function syncDevice(array $d): array
    {
        $mailQueue = [];
        $deviceId = $d['id'];
        $ip       = $d['ip'] ?? null;
        $status   = strtolower($d['status'] ?? 'offline');

        // 🔧 UPTIME CLOUD + LOCAL
        $uptime = $d['uptime'] ?? (
            ! empty($d['startupTime'])
                ? now()->diffInSeconds(Carbon::parse($d['startupTime']))
                : 0
        );
        $old = Device::where('device_id', $deviceId)->first();
        $oldStatus = $old?->status;
        $oldIp     = $old?->ip;
        $oldUptime = $old?->uptime;

        /* ===============================
         * HISTORY
         * =============================== */
        if (! $old) {
            DeviceHistory::create([
                'device_id'  => $deviceId,
                'event'      => 'adopted',
                'new_status' => $status,
                'new_ip'     => $ip,
                'new_uptime' => $uptime,
                'description' => 'Sistem mendeteksi perangkat baru.',
                'created_at' => now(),
            ]);
        } else {
            if ($oldStatus !== $status) {
                $event = $status === 'offline' ? 'offline' : 'online';
                DeviceHistory::create([
                    'device_id'   => $deviceId,
                    'event'       => $event,
                    'old_status'  => $oldStatus,
                    'new_status'  => $status,
                    'old_ip'      => $oldIp,
                    'new_ip'      => $ip,
                    'description' => $event === 'offline'
                        ? 'Perangkat tidak dapat dijangkau.'
                        : 'Perangkat kembali online.',
                    'created_at'  => now(),
                ]);

                $mailQueue[] = [
                    'subjek' => 'Notifikasi Device - '. ucfirst($event),
                    'image'  => $this->mapDeviceImage($d),
                    'icon'   => $event === 'offline' ? '🔴' : '🟢',
                    'title'  => $event === 'offline'
                        ? 'Device Offline'
                        : 'Device Online',
                    'description' => $event === 'offline'
                        ? 'Perangkat tidak dapat dijangkau oleh sistem.'
                        : 'Perangkat kembali online dan dapat diakses.',

                    'device_name' => $d['name'] ?? $d['model'],
                    'site_name'   => $d['hostName'] ?? 'LPSE - UDM',
                    'time'        => now()->format('d M Y H:i'),
                    'dashboard_url' => env('UNIFI_DASHBOARD_URL'),
                    'detail' => [
                        'IP Address' => $ip ?? '-',
                        'Status'     => ucfirst($status),
                    ],
                ];
            }

            if ($oldIp !== $ip) {
                DeviceHistory::create([
                    'device_id'  => $deviceId,
                    'event'      => 'ip_changed',
                    'old_ip'     => $oldIp,
                    'new_ip'     => $ip,
                    'description' => 'IP Address berubah.',
                    'created_at' => now(),
                ]);
                $mailQueue[] = [
                    'subjek' => 'Notifikasi Device IP Changed',
                    'image'  => $this->mapDeviceImage($d),
                    'icon'   => '🔁',
                    'title'  => 'IP Address Changed',
                    'description' => 'Sistem mendeteksi perubahan alamat IP pada perangkat.',
                    'device_name' => $d['name'] ?? $d['model'],
                    'site_name'   => $d['hostName'] ?? 'UKPBJ - SIDOARJO',
                    'time'        => now()->format('d M Y H:i'),
                    'dashboard_url' => env('UNIFI_DASHBOARD_URL'),
                    'detail' => [
                        'IP Lama' => $oldIp ?? '-',
                        'IP Baru' => $ip ?? '-',
                    ],
                ];
            }

            if ($oldUptime && $uptime < $oldUptime) {
                DeviceHistory::create([
                    'device_id'  => $deviceId,
                    'event'      => 'uptime_reset',
                    'old_uptime' => $oldUptime,
                    'new_uptime' => $uptime,
                    'description' => 'Perangkat restart.',
                    'created_at' => now(),
                ]);
            }
        }

        /* ===============================
         * UPSERT
         * =============================== */
        Device::updateOrCreate(
            ['device_id' => $deviceId],
            [
                'name'       => $d['name'] ?? $d['model'],
                'type'       => $this->mapDeviceType($d),
                'image'      => $this->mapDeviceImage($d),
                'model'      => $d['model'] ?? null,
                'ip'         => $ip,
                'mac'        => $d['mac'] ?? null,
                'uptime'     => $uptime,
                'status'     => $status,
                'site_id'    => $d['hostId'],
                'host_name'  => $d['hostName'],
                'checked_at' => now(),
                'updated_at'=> now(),
            ]
        );

        return $mailQueue;
    }

    /* =====================================================
     * EMAIL
     * ===================================================== */
    protected function sendUnifiMail(array $data): void
    {
        try {
            Mail::to(explode(',', env('UNIFI_NOTIFY_EMAIL')))
                ->send(new SendUnifiMail($data));
        } catch (\Throwable $e) {
            Log::channel('sync_log')->error('[DEVICE] Send email failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /* =====================================================
     * MAPPING
     * ===================================================== */
    private function mapDeviceType(array $d): string
    {
        $model = strtolower($d['model'] ?? '');

        return match (true) {
            str_contains($model, 'u6'),
            str_contains($model, 'u7'),
            str_contains($model, 'ac')  => 'access_point',
            str_contains($model, 'udm'),
            str_contains($model, 'ugw'),
            str_contains($model, 'usg') => 'router',
            str_contains($model, 'usw') => 'switch',
            default => 'other',
        };
    }

    private function mapDeviceImage(array $d): string
    {
        $model = strtolower($d['model'] ?? '');

        return match (true) {
            str_contains($model, 'u7')  => 'https://gesit.sidoarjokab.go.id/assets/images/unifi/u7_pro.png',
            str_contains($model, 'u6'),
            str_contains($model, 'ac')  => 'https://gesit.sidoarjokab.go.id/assets/images/unifi/u6_lite.png',
            str_contains($model, 'udm') => 'https://gesit.sidoarjokab.go.id/assets/images/unifi/udm_pro.png',
            str_contains($model, 'usg') => 'https://gesit.sidoarjokab.go.id/assets/images/unifi/usg_3p.png',
            str_contains($model, 'ugw') => 'https://gesit.sidoarjokab.go.id/assets/images/unifi/usg_3p.png',
            str_contains($model, 'usw') => 'https://gesit.sidoarjokab.go.id/assets/images/unifi/usw_48_poe.png',
            default                     => 'https://gesit.sidoarjokab.go.id/assets/images/unifi/unifi_default.png',
        };
    }
}
