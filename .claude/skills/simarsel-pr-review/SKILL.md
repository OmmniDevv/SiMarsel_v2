---
name: simarsel-pr-review
description: Prepare SiMarsel V2 PRs when asked to "siapin PR", "siapkan PR", "buat draft PR", or "prepare the PR". Also independently review SiMarsel V2 PRs when asked to "review PR" or given a SiMarsel V2 pull request link.
---

# SiMarsel PR Review

## Pilih mode

- **Siapkan PR.** Periksa diff branch kerja terhadap target PR, termasuk perubahan lokal yang akan diajukan. Jalankan pemeriksaan di bawah, lalu buat usulan judul dan isi PR. Jika keputusan scope atau verifikasi penting masih terbuka, rekomendasikan draft. Permintaan “siapin PR” menghasilkan draf di chat. Push atau buat, ubah, maupun tandai PR siap di GitHub hanya jika user secara eksplisit meminta tindakan itu.
- **Review independen.** Gunakan link PR untuk mengambil deskripsi, base, revisi terkini, diff, tiket terkait, dan status checks yang tersedia. Periksa kode terkait dan gunakan aturan proyek dari branch tujuan sebagai acuan. Verifikasi klaim penulis dari bukti yang tersedia. Laporkan di chat. Minta izin sebelum mengubah file, memposting review, atau melakukan merge.

## Pemeriksaan

1. **Uji scope tiket.** Baca epic, story, dan acceptance criteria yang relevan. Pisahkan perilaku yang dibutuhkan dari usulan teknisnya. Petakan perubahan skema, auth, izin, dan perilaku pengguna dari diff ke modul pemiliknya. Untuk setiap dampak lintas modul yang substantif, jelaskan tujuan awal PR, perilaku modul lain yang ikut berubah, bukti dari kode, dan kaitannya dengan kebutuhan tiket. Telusuri alternatif yang sudah ada dan dampak pada data lama bila relevan. Jika tiket tidak tersedia, pakai tujuan yang dinyatakan di PR sebagai acuan sementara dan tandai cakupan tiket **belum terverifikasi**. Jika kebutuhan atau kepemilikannya masih terbuka, ajukan pertanyaan spesifik ke maintainer dan tandai **Perlu keputusan**.
2. **Telusuri diff dan jalankan cek yang relevan.** Pakai `AGENTS.md` untuk menilai bagian yang berubah, misalnya Form Request, policy, service, dan Blade component yang sudah ada. Prioritaskan bug, regresi, risiko data lama, dan acceptance criteria yang belum terpenuhi. Bila lingkungan lokal atau test tersedia, jalankan tes Laravel yang relevan dan build frontend jika aset berubah.
3. **Cek UI saat UI atau kontrak API berubah.** Perubahan endpoint, input, respons, status, atau error API memicu cek ini meski diff hanya menyentuh backend. Telusuri pemanggil di frontend yang akan berjalan bersama revisi PR. Cocokkan request yang dikirim dan respons yang dipakai dengan kontrak baru. Jika butuh PR frontend pendamping, sebutkan dependensi dan urutan rilisnya. Jika ada aplikasi lokal atau preview untuk revisi yang diperiksa, gunakan data uji untuk mencoba alur utama dan satu kondisi gagal yang relevan. Bandingkan perubahan tampilan dengan halaman acuan jika tersedia. Catat hasil serta bukti browser yang tersedia. Jika aplikasi tidak bisa dibuka, laporkan hasil pemeriksaan kode dan tandai **UI belum diuji di browser**. Jika API tidak dipakai UI, laporkan hasil penelusuran itu.
4. **Laporkan sesuai kekuatan bukti.** Bedakan hasil yang diamati langsung, hasil dari CI atau penulis, hasil baca kode, dan cek yang belum bisa dilakukan. Cocokkan hasil tes dengan revisi yang direview jika bisa. Jika akses tiket, diff, atau preview terbatas, catat dampaknya pada kesimpulan. Lanjutkan bagian yang bisa diperiksa dan minta hanya konteks yang diperlukan untuk keputusan.

## Keluaran

Tampilkan bagian **Dampak lintas modul** di kedua mode, sekalipun ada bug yang lebih mendesak. Gunakan satu poin ringkas per modul terdampak dengan bukti dan status keputusan. Jika tidak menemukan dampak lintas modul, nyatakan itu sesuai cakupan yang diperiksa.

- **Siapkan PR:** judul, ringkasan perilaku yang berubah, bagian **Konteks tiket**, dampak lintas modul, bukti UI jika ada, serta keputusan atau cek penting yang masih terbuka.
- **Review independen:** mulai dengan **Perlu keputusan**, **Perlu revisi**, atau **Siap ditinjau maintainer**. Tampilkan **Dampak lintas modul** sebelum daftar temuan teknis, di luar batas tiga temuan teknis. Untuk tiap temuan teknis, tulis bukti (`file:baris`, acceptance criterion, atau hasil tes), dampak, dan tindakan berikutnya. Akhiri dengan cek penting yang sudah dan belum dilakukan. Jika tak ada temuan, laporkan sesuai cakupan yang berhasil diperiksa.

### Konteks tiket untuk Siapkan PR

Selalu buat bagian **Konteks tiket** di deskripsi PR. Ambil tiket yang dirujuk bila bisa diakses. Ringkas hanya informasi yang diperlukan untuk menilai diff.

- **Tujuan parent.** Sertakan ID atau link parent dan subtask bila tersedia. Ringkas hasil yang ingin dicapai dari parent dalam satu atau dua kalimat.
- **AC subtask.** Cantumkan ID AC beserta ID subtask jika PR mencakup beberapa tiket. Pilih AC yang dikerjakan atau terdampak. Pertahankan aturan bisnis, skenario batas, dan perilaku lama yang wajib dijaga jika menentukan lulus atau gagal.
- **Batas scope.** Nyatakan yang masuk PR, yang di luar scope, serta dependensi yang dikerjakan di tiket atau PR lain. Tandai konflik atau ketidakjelasan sebagai **Perlu keputusan**.
- **Bukti tes.** Kaitkan tiap AC dengan skenario atau perintah yang relevan dan hasil yang benar-benar tersedia. Bedakan tes yang dijalankan sendiri dari hasil CI atau laporan penulis. Untuk AC tanpa bukti, tulis **belum diuji** atau **hasil belum terverifikasi**, sesuai kondisinya. Skenario tes di tiket belum membuktikan tes itu sudah dijalankan.

Jika tiket tidak bisa dibuka, pakai kutipan atau ringkasan yang diberikan dan tandai **cakupan tiket belum terverifikasi**. Isi yang bisa dipastikan dan tandai bagian yang belum tersedia. Jangan menyimpulkan persyaratan dari diff saja atau mengarang AC dan hasil tes. Jika masih perlu konteks, minta bagian yang hilang saja.

Di mode **Review independen**, gunakan **Konteks tiket** dari PR sebagai acuan sementara jika tiket asli tidak bisa dibuka. Tetap tandai **cakupan tiket belum terverifikasi**.
