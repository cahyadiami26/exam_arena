# E-Assessment PT Lampung Jasa Utama

Pondasi aplikasi web E-Assessment berbasis **PHP native + MySQL**. Autentikasi tetap ditangani oleh SSO LJU; aplikasi ini membaca sesi `LJU_APPS` dan tidak menyimpan atau memvalidasi kata sandi pengguna.

> Pengerjaan mengikuti permintaan bertahap. Fondasi konfigurasi/SSO, dashboard, lobby, runner ujian, CRUD bank soal, builder modul/paket, jadwal/undangan, matrix alokasi reviewer–subjek 360°, portal review HR, laporan HR/Manajemen, ekspor PDF, laporan skor mentah PAPI, dan leaderboard internal HR/Admin sudah tersedia. Interpretasi PAPI berbasis norma tetap menunggu pedoman resmi dan tidak dibuat tanpa sumber tersebut.

## Kebutuhan

- PHP 8.1+ dengan ekstensi `pdo_mysql`, `mbstring`, `dom`, `iconv`, `fileinfo`, dan `openssl`.
- MySQL 8.0+ (atau MariaDB yang mendukung kolom JSON dan InnoDB).
- Dua koneksi basis data: basis data aplikasi E-Assessment dan basis data SSO yang berisi tabel `users`. Keduanya boleh berada di mesin yang sama.
- Web root nantinya diarahkan ke folder `public/`; direktori penyimpanan unggahan harus berada di luar web root.

## Instalasi dan deployment

Ikuti urutan ini pada host Linux/PHP-FPM. Sesuaikan direktori dan nama pengguna web server dengan lingkungan deployment; jangan arahkan web root ke akar repository.

1. **Siapkan runtime.** Gunakan PHP 8.1 atau lebih baru dengan `pdo_mysql`, `mbstring`, `dom`, `iconv`, `fileinfo`, dan `openssl`; siapkan MySQL 8.0+ atau MariaDB yang mendukung JSON/InnoDB. Pada cPanel, pilih versi PHP dan aktifkan ekstensi melalui MultiPHP Manager/Select PHP Version. `curl`, `unzip`, dan `sha256sum` hanya diperlukan jika membangun ulang pustaka PDF di host Linux; tidak diperlukan untuk instalasi cPanel dari berkas repository/ZIP. Periksa runtime dan ekstensi dengan `php -v` serta `php -m` jika akses terminal tersedia.
2. **Salin source aplikasi.** Checkout/copy repository ke direktori aplikasi, misalnya `/srv/eassessment`. Tidak diperlukan Composer, `composer install`, atau file `.env`; konfigurasi diberikan melalui environment PHP/server.
3. **Atur web server dan HTTPS.** Arahkan document root ke `/srv/eassessment/public` (folder `public/` pada lokasi aktual), bukan ke akar repository. Aktifkan PHP-FPM dan TLS/HTTPS untuk deployment produksi. Sediakan aturan rewrite hanya jika konfigurasi web server memerlukannya; halaman PHP saat ini dapat diakses melalui nama file seperti `index.php` dan `lobby.php`.
4. **Siapkan database aplikasi dan SSO.** Buat database E-Assessment dan akun runtime aplikasi, lalu impor `database/schema.sql` sesuai langkah pada bagian [Menyiapkan basis data](#menyiapkan-basis-data). Jalankan impor menggunakan akun deployment yang diizinkan membuat tabel; akun runtime aplikasi cukup memperoleh hak baca/tulis yang diperlukan pada database aplikasi. Buat/siapkan akun SSO terpisah dengan hak `SELECT` saja pada tabel `users` dan kolom yang tercantum pada bagian basis data. Jangan mengubah skema atau password SSO.
5. **Pasang konfigurasi environment.** Isi variabel pada bagian [Konfigurasi](#konfigurasi) di environment yang benar-benar diwariskan PHP-FPM (misalnya konfigurasi pool PHP-FPM atau mekanisme rahasia server). Jangan menaruh password database di Git atau di dalam `public/`. Cocokkan `APP_URL`, `SSO_TARGET_SYSTEM`, serta `SSO_LOGIN_URL`; untuk produksi di domain LJU gunakan `SESSION_COOKIE_DOMAIN=.lju.co.id` dan HTTPS. Kosongkan domain cookie hanya untuk pengembangan lokal.
6. **Siapkan penyimpanan privat.** Buat `storage/` di akar aplikasi, di luar `public/` dan di luar document root. Pada cPanel, buat folder melalui File Manager dan atur permission sesuai kebijakan hosting (umumnya `0750` atau `0755`); pastikan handler PHP dapat menulis ke folder tersebut, tanpa memakai `0777`. Di VPS Linux, atur owner/group ke user PHP-FPM. Aplikasi menaruh unggahan dan cache PDF di sana.
7. **Siapkan DOMPDF tanpa Composer.** Pustaka DOMPDF beserta dependensi dan lisensinya sudah dibundel di `lib/dompdf/`, dan arsip `dompdf-cpanel.zip` menyediakan folder yang sama untuk upload terpisah. Pada cPanel tanpa Terminal/SSH, unggah repository atau `dompdf-cpanel.zip` ke akar aplikasi (folder yang berisi `app/` dan `public/`), lalu ekstrak ZIP dengan struktur direktori tetap; hasilnya harus berada di `lib/dompdf/`, bukan `public/lib/`. Tidak perlu menjalankan script atau Composer di cPanel. `scripts/install_dompdf.sh` tetap tersedia secara opsional untuk membangun ulang versi pustaka yang dipatok di host/build environment Linux.
8. **Terapkan konfigurasi PHP.** Pada cPanel, pastikan versi dan ekstensi PHP aktif di MultiPHP Manager/Select PHP Version; konfigurasi biasanya diterapkan oleh hosting tanpa akses restart layanan. Pada VPS, reload PHP-FPM/web server bila environment atau konfigurasi berubah. Periksa error log PHP dan izin tulis.
9. **Lakukan pemeriksaan awal.** Dengan environment konfigurasi tersedia di shell, jalankan pemeriksaan baca-saja berikut:

   ```sh
   php -l public/index.php
   php -r 'require "app/database.php"; db(); sso_db(); echo "Kedua koneksi database OK", PHP_EOL;'
   php -r 'require "app/dompdf_loader.php"; if (!app_load_dompdf()) { fwrite(STDERR, "DOMPDF belum siap", PHP_EOL); exit(1); } echo "DOMPDF siap", PHP_EOL;'
   ```

   Environment CLI harus sama dengan konfigurasi yang dibaca PHP-FPM; jangan menyalin password ke perintah atau riwayat shell. Setelah itu buka `APP_URL`: tanpa sesi SSO, pengguna seharusnya diarahkan ke Portal SSO. Uji login, lobby, menu sesuai peran HR/Admin, dan ekspor PDF setelah DOMPDF siap. Aplikasi tidak menyediakan kredensial database default.

Untuk pembaruan berikutnya, deploy source baru tanpa menyalin direktori `storage/` atau menghapus unggahan/cache privat. Cadangkan database dan `storage/` sesuai kebijakan operasional sebelum melakukan perubahan skema atau pemeliharaan.

## Menyiapkan basis data

Buat basis data khusus E-Assessment, kemudian impor skema dari folder tersebut:

```sh
mysql -u USER -p NAMA_DATABASE_ASSESSMENT < database/schema.sql
```

Skema tidak membuat ulang atau mengubah tabel SSO `users`. ID karyawan disimpan sebagai referensi ke `users.id` tanpa foreign key lintas-basis-data.

Akun untuk koneksi SSO sebaiknya hanya memiliki izin `SELECT` pada kolom direktori yang dibutuhkan (`id`, `nik`, `name`, `email`, `position`, `division`, `department`, `username`, `avatar`, `is_active`). Aplikasi tidak mengambil kolom `password` maupun kredensial webmail.

## Konfigurasi

Konfigurasi dibaca dari environment PHP/server (tidak ada file `.env` atau dependensi Composer). Nilai minimum untuk deployment:

```text
APP_URL=https://assessment.lju.co.id
APP_ENV=production
APP_DEBUG=false
SSO_LOGIN_URL=https://lju.co.id/dashboard/
SSO_TARGET_SYSTEM=https://assessment.lju.co.id
SESSION_COOKIE_DOMAIN=.lju.co.id
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=lju_eassessment
DB_USERNAME=assessment_app
DB_PASSWORD=...
SSO_DB_HOST=127.0.0.1
SSO_DB_PORT=3306
SSO_DB_DATABASE=lju_sso
SSO_DB_USERNAME=assessment_directory_readonly
SSO_DB_PASSWORD=...
```

`SESSION_COOKIE_DOMAIN` dapat dikosongkan untuk pengembangan lokal. Koneksi PDO menyetel zona waktu sesi MySQL dari `APP_TIMEZONE` agar `NOW()` dan jendela jadwal konsisten dengan input WIB. Gunakan kredensial database melalui konfigurasi PHP-FPM/web server dan jangan menyimpan rahasia di Git.

## Sesi SSO dan hak akses

Bootstrap memakai nama sesi `LJU_APPS`, domain cookie `.lju.co.id`, `HttpOnly`, `SameSite=Lax`, dan `Secure` ketika request HTTPS. Pengguna tanpa `$_SESSION['user_id']` diarahkan ke Portal SSO dengan parameter `target_system` dan `redirect_after_login`.

Pemetaan nama di aplikasi:

- `users.division` → `nama_bidang` (contoh: SDM).
- `users.department` → `nama_divisi`.

Pemetaan akses awal: `role=admin`/posisi System Administrator memperoleh akses admin; bidang SDM atau role HR/assessor memperoleh akses HR; posisi manajerial/Direksi memperoleh akses manajemen. Semua akun SSO yang valid tetap dapat menjadi peserta. Middleware HR/Admin dan manajemen sudah menerapkan pemetaan tersebut pada halaman yang tersedia.

## Jadwal dan undangan

HR/Admin mengelola jadwal di `public/admin/schedules.php` dan hanya dapat memilih paket yang sudah diterbitkan. Waktu formulir memakai zona Asia/Jakarta. Karyawan dicari langsung dari direktori `users` pada database SSO tanpa membatasi role; kandidat eksternal menerima token acak yang hanya disimpan sebagai hash SHA-256 di `schedule_participants`.

Token kandidat mentah hanya tersedia sekali pada respons HR setelah undangan dibuat. Schema sudah menyediakan `notification_outbox`, tetapi repositori belum memiliki mailer/worker aktif; karena itu token dan tautan lobby perlu dibagikan HR melalui kanal resmi, bukan dianggap sudah terkirim otomatis. Paket evaluasi 360° dibatasi ke mode akses karyawan SSO dan satu percobaan per reviewer; matrix reviewer → subjek diatur saat jadwal berstatus Terjadwal, setiap reviewer aktif wajib memiliki subjek, reviewer tidak dapat menilai dirinya sendiri, dan daftar reviewer maupun matrix terkunci setelah sesi ujian pertama. Jadwal 360° baru dapat dibuka setelah semua reviewer dialokasikan dan tidak berisi undangan kandidat. Pengaturan pokok jadwal terkunci setelah ada undangan aktif atau sesi ujian; isi/status paket juga dikunci selama ada peserta terundang. Status jadwal tetap dapat ditutup/diarsipkan melalui panel status.

## Penilaian HR

Portal penilai berada di `public/admin/reviews.php`. HR/Admin dapat menilai jawaban esai dan praktik dengan skor antara 0 dan bobot maksimum soal; skor tersimpan pada item review dan jawaban, lalu dikonsolidasikan ke nilai sesi setelah seluruh item review peserta selesai. Item PAPI, psikometri, dan 360° menerima rekomendasi/catatan kualitatif. Panel PAPI di workbench HR menampilkan grafik radar berskala dinamis untuk nilai mentah positif/negatif serta tabel per aspek dengan label/kelompok dari contoh yang diberikan. Grafik tidak menerapkan norma atau interpretasi; interpretasi lengkap tetap diisi HR melalui review kualitatif. Berkas jawaban tetap privat dan hanya dapat diunduh oleh HR/Admin melalui handler terotorisasi.

Laporan HR di `public/reports.php` juga menampilkan skor mentah PAPI per attempt dan menyediakan CSV (maksimal 1.000 attempt PAPI, satu baris per aspek). Skor dibaca langsung dari `attempt_papi_scores`; laporan tidak menggabungkan skor antar peserta/attempt, menormalisasi, memberi label normatif, atau membuat interpretasi. Panel layar dibatasi 50 attempt terbaru dan mengikuti filter periode, paket, jadwal, serta status.

## Leaderboard HR/Admin

Leaderboard tersedia di `public/admin/leaderboard.php` dan hanya dapat diakses HR/Admin. Peringkat dipilih per jadwal, berdasarkan skor akhir tertinggi dari attempt berstatus selesai untuk setiap peserta; jika ada retake dengan skor terbaik yang sama, attempt terbaru dipilih. Peserta dengan skor akhir sama berbagi peringkat kompetisi (misalnya 1, 1, 3). Attempt yang menunggu review dan skor final kosong tidak dirangking; halaman menampilkan maksimal 500 peserta teratas.

Jadwal dengan paket yang memuat item PAPI atau modul 360° tidak ditawarkan pada leaderboard karena skor final instrumen tersebut tidak dipakai sebagai dasar peringkat. Tidak ada identitas atau ranking yang ditampilkan ke peserta maupun akun Manajemen.

## Laporan HR dan Manajemen

Halaman `public/reports.php` memuat periode (maksimal 10 tahun), paket, jadwal, dan status attempt. HR/Admin dapat melihat rincian peserta per attempt serta mengunduh CSV (maksimal 5.000 baris) atau PDF (maksimal 500 attempt terbaru); teks CSV yang berpotensi menjadi formula spreadsheet dinetralkan. Peserta dengan akun SSO tetap dapat muncul sebagai peserta tanpa pembatasan role SSO.

Akun Manajemen non-HR hanya melihat agregat per paket—tanpa nama, email, ID SSO, atau rincian attempt—dan tidak memperoleh ekspor individual. Rata-rata memerlukan sedikitnya 5 peserta berbeda dengan skor final; tingkat lulus memerlukan sedikitnya 5 peserta berbeda dengan nilai ambang. Identitas unik dihitung dari ID SSO karyawan atau profil kandidat. Nilai dan tingkat lulus dihitung per attempt selesai (retake dihitung terpisah); laporan tidak mengubah hasil atau keputusan asesmen.

Peserta karyawan SSO maupun kandidat bertoken dapat melihat dan mengunduh PDF ringkasan milik sendiri dari halaman hasil attempt. Grafik radar dan tabel skor mentah PAPI tersedia di halaman hasil serta PDF hanya setelah attempt selesai dan review PAPI HR benar-benar selesai; endpoint tetap memverifikasi kepemilikan attempt, akun Manajemen tanpa peran HR/Admin tidak memperoleh profil PAPI, dan catatan/rekomendasi internal HR tidak ditampilkan. PDF tidak menyertakan identitas SSO atau peserta lain. Saat review belum selesai, skor objektif diberi label sementara dan skor akhir disembunyikan.

## Unggahan dan PDF

File jawaban, lampiran psikotes, serta referensi soal harus disimpan di `storage/` di luar web root, divalidasi berdasarkan MIME/ekstensi dan batas ukuran, dan diunduh melalui handler yang memeriksa hak akses. Laporan memiliki ekspor PDF untuk HR/Admin (rincian peserta) dan Manajemen (agregat tanpa identitas), dengan batas akses yang sama seperti halaman. DOMPDF 3.1.6 dan empat dependensi runtime yang dipatok versinya dibundel di `lib/dompdf/`; arsip `dompdf-cpanel.zip` berisi struktur pustaka yang sama beserta panduan cPanel. Pada cPanel, unggah arsip ke akar aplikasi melalui File Manager dan ekstrak dengan path tetap agar `lib/dompdf/` berada sejajar dengan `app/` dan `public/`. Tidak perlu shell, Composer, atau menjalankan installer di host cPanel. Untuk membangun ulang paket secara manual pada Linux, jalankan `./scripts/install_dompdf.sh`; skrip memverifikasi checksum sumber dan menyalin lisensi. Renderer menonaktifkan akses remote, JavaScript, dan PHP tertanam; font/temp cache dibuat di `storage/dompdf/` di luar web root. Pastikan user PHP-FPM dapat menulis ke `storage/` dan ekstensi `dom`, `mbstring`, serta `iconv` aktif. Jika pustaka belum terpasang, endpoint PDF mengembalikan pesan setup tanpa membocorkan path server.
