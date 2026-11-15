# SiMarsel V2

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

- SiMarsel adalah aplikasi internal Cerebrum untuk analitik pemasaran dan penjualan, pengelolaan promo, serta pekerjaan tim. Fokus pengguna adalah divisi kerja Marketing Sales.
- Pengguna yang diprioritaskan adalah manajer dan supervisor sub-divisi yang memantau penjualan.
- Pengguna lain mencakup Admin dan Staff. Role akun yang saat ini tersedia di kode adalah Admin (`ADM`), Manager (`MGR`), dan Employee (`EMP`). Supervisor adalah peran operasional yang disebut pengguna; pemetaannya ke role akun belum dikonfirmasi.

## Product Purpose

Menyatukan pemantauan performa penjualan lintas platform, analisis promo, dan pengelolaan tugas dalam satu aplikasi internal Cerebrum untuk manajer dan staf, dengan akses sesuai role.

Masalah utama yang dikonfirmasi pengguna adalah antarmuka yang terasa generik dan seperti keluaran AI, susunan yang kurang teratur, serta data yang sulit dibaca karena kurang terstruktur. Prioritas pengembangan adalah membantu manajer dan supervisor memahami data penjualan dengan struktur informasi yang jelas.

Tujuan redesign yang dikonfirmasi adalah pembaruan visual seluruh aplikasi agar grafik, tabel, dan form konsisten serta mudah dipakai sehari-hari. Ringkasan Performa, Kelola Kode, dan Input Massal menjadi layar acuan. Fungsi dan aturan bisnis yang sudah ada harus dipertahankan; arah visual baru masih terbuka.

## Operating Context

- Digunakan sehari-hari untuk pekerjaan internal Cerebrum, khususnya Marketing Sales dan pemantauan penjualan oleh manajer serta supervisor sub-divisi.
- Alur yang sudah ada mencakup pemilihan periode dan platform, pembacaan ringkasan serta tren performa, analisis penggunaan kode promo, dan pengelolaan tugas sampai pengumpulan evidence serta review QA.
- Pada analisis promo, dua KPI utama pemantauan harian adalah Revenue Promo dan Transaksi Promo. Metrik lainnya tetap tersedia sebagai konteks pendukung.
- Pilihan penyajian `/promo` yang sudah dikonfirmasi: toolbar filter ringkas dengan detail yang dapat dibuka; grafik tren tetap satu grafik gabungan, dengan satuan dan legenda yang diperjelas.
- Alur utama Input Massal adalah mengisi baris secara manual melalui form input massal. Hierarki form, navigasi antar-field, penambahan baris, validasi per baris, dan hasil penyimpanan perlu mendukung alur ini. Paste Excel/Sheets tetap menjadi kemampuan yang tersedia.
- Login memakai session Laravel. Akses fitur dan cakupan data mengikuti otorisasi backend.
- Perangkat utama dan kebiasaan pelaporan pengguna belum dikonfirmasi.

## Capabilities and Constraints

### Kemampuan yang sudah tercatat di repository

- Dashboard performa: revenue, transaksi, downloader, user unik, pembelian perdana, repeat order, AOV, konversi, tren, serta perbandingan periode.
- Analisis produk, kontribusi platform, metode pembayaran, distribusi waktu pembelian, dan ekspor performa.
- Master dan analisis kode promo, funnel, source, serta kode yang belum terdaftar. Dokumen promo menjelaskan transaksi sumber dari Markaz dan pelestarian snapshot klasifikasi penggunaan kode.
- Tasklist: Board, Table, Timeline, Calendar, penugasan PIC, prioritas, tenggat, evidence, review QA, dan histori aktivitas.
- Pengelolaan akun serta divisi dengan pembatasan akses sesuai kewenangan.

### Batasan pengembangan

- Pertahankan arsitektur monolit Laravel dan pola service-first sesuai `AGENTS.md`.
- Laravel dipatok pada versi 13.33.0; peningkatan versi memerlukan persetujuan.
- Antarmuka memakai Blade, Tailwind CSS v4, Alpine.js, Chart.js, Vite, dan native `fetch()`. Pengenalan framework SPA memerlukan persetujuan.
- Rumus dan agregasi laporan berada di backend. Perubahan presentasi harus mempertahankan makna metrik, filter, periode pembanding, serta aturan atribusi promo yang terdokumentasi.
- Otentikasi, otorisasi, validasi server, dan pembatasan data per role tetap menjadi aturan aplikasi.
- Redesign mencakup visual seluruh aplikasi dengan Ringkasan Performa, Kelola Kode, dan Input Massal sebagai layar acuan. Perubahan visual harus mempertahankan fungsi serta aturan bisnis yang sudah ada.

### Keputusan terbuka

- Pemetaan supervisor ke role akun dan cakupan divisinya.
- Prioritas KPI di luar analisis promo belum dikonfirmasi. Untuk analisis promo, Revenue Promo dan Transaksi Promo sudah ditetapkan sebagai dua KPI utama. Layar acuan redesign adalah Ringkasan Performa, Kelola Kode, dan Input Massal.
- Kebijakan bahasa antarmuka: kode saat ini menggunakan Bahasa Indonesia dengan beberapa istilah bisnis berbahasa Inggris; standar bahasa belum dikonfirmasi.
- Perangkat sasaran dan kebutuhan aksesibilitas khusus pengguna.

## Brand Commitments

Nama produk adalah SiMarsel V2; antarmuka saat ini menampilkan SiMarsel. Produk digunakan secara internal oleh Cerebrum. Arah visual baru masih terbuka; belum ada palet, tipografi, atau gaya visual baru yang ditetapkan pengguna.

## Evidence on Hand

- Konfirmasi pengguna dalam sesi init: aplikasi internal kantor, prioritas Marketing Sales terutama manajer dan supervisor sub-divisi, serta masalah struktur dan keterbacaan antarmuka.
- Klarifikasi pengguna: organisasi Cerebrum, penggunaan harian oleh manajer dan staf sesuai role, tujuan redesign visual seluruh aplikasi, tiga layar acuan, pelestarian fungsi dan aturan bisnis, serta arah visual yang masih terbuka.
- Jawaban setelah critique `/promo`: Revenue Promo dan Transaksi Promo sebagai KPI utama; pengisian baris manual melalui form sebagai alur utama Input Massal.
- `routes/web.php`: halaman login, dashboard performa, produk, tugas, promo, dan pengaturan.
- `resources/views/` dan `resources/js/`: implementasi antarmuka yang sudah ada; menjadi bahan pemeriksaan saat perubahan desain diminta.
- `docs/api/performance.md` dan dokumen performa terkait: definisi KPI, filter, agregasi, dan kontrak laporan.
- `docs/promo-codes.md` serta `docs/api/promo-*.md`: normalisasi, riwayat klasifikasi, dan kontrak promo.
- `docs/api/tasks.md`: alur tugas, evidence, QA, jadwal, dan cakupan akses.
- `docs/api/authentication.md`: kontrak autentikasi berbasis session.
- Data contoh atau seeder tidak membuktikan hasil penjualan nyata. Jangan membuat klaim performa bisnis dari data contoh.

## Product Principles

1. Utamakan kebutuhan manajer dan supervisor Marketing Sales dalam membaca serta memantau penjualan.
2. Susun informasi agar angka, konteks periode, filter, dan perbandingan mudah dipahami bersama.
3. Gunakan grafik, tabel, dan form yang konsisten lintas aplikasi untuk pekerjaan sehari-hari; hindari dekorasi generik yang mengaburkan informasi.
4. Pertahankan ketepatan makna data dan aturan bisnis ketika memperbaiki antarmuka.
5. Bedakan peran operasional pengguna dari kewenangan akun; setiap akses mengikuti otorisasi backend.
