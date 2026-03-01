# 📡 Backend Monitoring Jaringan UniFi (Laravel)

Aplikasi ini merupakan **backend service berbasis Laravel** yang digunakan untuk mendukung sistem **monitoring jaringan UniFi**.  
Aplikasi berfungsi melakukan **sinkronisasi data perangkat jaringan**, **pemantauan status koneksi internet (WAN)**, serta **pengiriman notifikasi gangguan secara otomatis melalui email**.

---

## ✨ Fitur Utama
- Sinkronisasi data perangkat jaringan dari **UniFi Controller (Cloud & Local)**
- Monitoring status perangkat (online / offline)
- Monitoring status koneksi internet (WAN)
- Deteksi **failover** dan **pemulihan koneksi**
- Pencatatan histori perubahan status perangkat dan WAN
- Pengiriman **notifikasi email otomatis**
- Proses berjalan terjadwal menggunakan **Laravel Scheduler**

---

## 🧰 Teknologi yang Digunakan
- **Framework**: Laravel 9
- **Bahasa Pemrograman**: PHP 8
- **Database**: MySQL
- **Web Server**: Nginx
- **Mail Service**: SMTP
- **API**: UniFi Controller API
- **Version Control**: Git & GitHub

---

## 📁 Struktur Umum Aplikasi
- **Models**: Device, DeviceHistory, InternetStatus, WanFailoverHistory
- **Services**:
  - UnifiDeviceSyncService
  - UnifiWanSyncService
- **Artisan Commands**:
  - `unifi:sync-devices`
  - `unifi:sync-wan`
- **Scheduler**: Sinkronisasi otomatis setiap 10 menit
- **Mail**: Notifikasi email berbasis Laravel Mailable

---

## ▶️ Cara Menjalankan
1. Konfigurasi environment pada file `.env`
2. Jalankan migrasi database:
   ```
   php artisan migrate
   ```
3. Jalankan scheduler:
   ```
   php artisan schedule:run
   ```
4. Atau jalankan manual:
   ```
   php artisan unifi:sync-devices
   php artisan unifi:sync-wan
   ```

---

## 📈 Output Sistem
- Data perangkat dan status WAN tersimpan di database
- Riwayat perubahan status tercatat otomatis
- Email notifikasi terkirim saat terjadi:
  - Perangkat offline / online
  - Perubahan IP perangkat
  - Failover dan pemulihan koneksi internet

---

## 🎓 Konteks Akademik
Aplikasi backend ini dikembangkan sebagai bagian dari **sistem monitoring jaringan** untuk keperluan **penelitian dan tugas akhir**, khususnya dalam mendukung pengawasan ketersediaan jaringan dan penyampaian informasi gangguan secara real-time.

---

## 👤 Penulis
**Syaiful Ulum**  
Backend Monitoring Jaringan UniFi – Laravel