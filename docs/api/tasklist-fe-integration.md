# Integrasi FE Tasklist setelah TASK-19

Panduan untuk tim FE: data yang dikirim backend setelah TASK-19 (STEAM-268), selisih dengan kode FE saat ini, dan fitur
mana yang sudah siap diintegrasikan. Kontrak lengkap tetap di [tasks.md](tasks.md); dokumen ini merangkum yang berubah.

Diverifikasi pada 16 Nov 2026 terhadap branch `feat/DB-tasklist-database-foundation` dan kode FE di `dev`
(`resources/js/pages/tasks/*`, `resources/js/services/tasks.js`, `resources/views/pages/tasks/components/*`).

## 1. Data yang dibutuhkan FE

| Kebutuhan | Sumber | Bentuk |
|---|---|---|
| Daftar PIC aktif | `Task.pics` (daftar, Board, Table, Calendar `deadlines`, detail) | `[{ id, fullname }]`, urut nama; `[]` bila belum ada PIC |
| Riwayat PIC | `TaskDetail.assignments` | `[{ id, account, assigned_by, assigned_at, unassigned_by, unassigned_at, is_active, note }]`, terlama dulu |
| Pilihan PIC (buat & atur) | `create-options.pics`, `assignment-options.pics` | `[{ id, fullname, role, division: { id, code_division, name } \| null }]` dari semua divisi |
| Status | `Task.status`, `meta.statuses` | `backlog`, `todo`, `in_progress`, `ready_review`, `on_review`, `done` |
| Hak akses umum | `meta.role`, `meta.role_code`, `meta.permissions` | `role` tetap `admin`/`manager`/`staff`; `role_code` = `ADM`/`MGR`/`SPV`/`EMP`/`FRL`; `permissions` = kode `task.*` |
| Hak akses per tugas | `can_view_detail`, `can_edit`, `can_assign`, `can_review`, `editable_fields`, `next_statuses` | Boolean/daftar dari backend; FE tidak perlu menghitung ulang |
| Versi (optimistic locking) | `Task.version` | Kirim di setiap `PATCH`/`POST` perubahan; `409 { current_version }` bila usang |
| Cakupan daftar | `GET /api/tasks?scope=` | `all_tasks` (default) atau `my_tasks` |

## 2. Selisih FE saat ini dengan backend

Kode FE di `dev` masih memakai kontrak sebelum TASK-19. Semua baris di bawah perlu disesuaikan **sebelum atau bersamaan
dengan** merge branch backend ini; tanpa itu, Board tidak menampilkan tugas Ready Review/On Review dan form buat/atur PIC
ditolak `422`.

### Status

| File | Sekarang | Ganti menjadi |
|---|---|---|
| `resources/js/pages/tasks/workflow.js` (STATUSES) | `ready_for_qa` "Ready for QA", `on_qa` "On QA" | `ready_review` "Ready Review", `on_review` "On Review" |
| `workflow.js` (`LOCKED_ASSIGNMENT`, transisi, `summary`) | `ready_for_qa`, `on_qa` | `ready_review`, `on_review` |
| Monitoring (`GET /api/tasks/monitoring`) | metrik `ready_for_qa`, `on_qa` | `ready_review`, `on_review` |

### PIC

| File | Sekarang | Ganti menjadi |
|---|---|---|
| `workflow.js` `isPic()` | `task.pic?.id === user.id` | `task.pics.some((pic) => pic.id === user.id)` |
| `workflow.js` cek Back Log → To Do | `!task.pic` | `task.pics.length === 0` |
| `index.js` sort/filter PIC | `task.pic?.fullname` | `task.pics.map((pic) => pic.fullname).join(', ')`; atau kirim `sort=pic` ke server (memakai PIC pertama menurut abjad) |
| `index.js` + `task-create-modal.blade.php` | `form.pic_id` (satu `<select>`), PIC diambil dari `divisions[].pics` | `form.pic_ids` (pilih banyak), opsi dari `create-options.pics` (semua divisi; boleh dikelompokkan dengan `division`) |
| `index.js` validasi To Do | `!pic_id` | `pic_ids.length === 0`; error backend di `errors.pic_ids` |
| `index.js` + `task-detail.blade.php` atur PIC | `assignForm.pic_id`, `current_pic`, `divisions[].pics`, tampil `task.pic` | `pic_ids` = **daftar PIC lengkap** setelah perubahan (PIC yang tidak dikirim dilepas), opsional `note`; tampilkan `current_pics`/`task.pics`; opsi dari `assignment-options.pics`; `division_id` hanya untuk Admin meneruskan |
| `index.js` toast setelah atur PIC | `data.pic?.fullname` | `data.pics` |
| `index.js` pesan error | `errors.pic_id` | `errors.pic_ids` atau `errors['pic_ids.N']` (N = urutan PIC yang ditolak) |
| `task-board.blade.php`, `task-calendar.blade.php`, `task-detail.blade.php` | `task.pic`, `task.pic.fullname` | `task.pics` (bisa lebih dari satu) |
| `services/tasks.js` (komentar kontrak) | `pic_id` | `pic_ids`, `pics` |

### Lainnya

| Hal | Catatan |
|---|---|
| `PATCH /api/tasks/{id}` | Tidak lagi mengubah PIC; `pic_id`/`pic_ids` diabaikan. Gunakan endpoint assignment. |
| Histori | Perubahan PIC baru memakai `changes.pics` (daftar) dan action `assigned`/`unassigned`/`reassigned`; histori lama tetap `changes.pic`. Pakai `description` dari backend untuk teks. Urutan field dan `{ old, new }` di `changes` kini selalu sama di semua database. |
| Hapus akun | `DELETE /api/accounts/{id}` bisa `422` bila akun punya riwayat tugas; tampilkan `message`. |
| Role Supervisor/Freelance | `meta.role` mereka masih `staff` sampai TASK-12; pakai `role_code` bila perlu membedakan. |

## 3. Kesiapan per fitur

| Fitur | Backend | Catatan untuk FE |
|---|---|---|
| Daftar `all_tasks` / `my_tasks`, Board, Table, filter, pagination | ✅ Siap | `all_tasks` masih mengikuti visibilitas lama sampai TASK-12 |
| Task Detail (pics, assignments, history, evidence, reviews, version) | ✅ Siap | |
| Buat tugas dengan banyak PIC | ✅ Siap | |
| Atur PIC (tambah/lepas/ganti, lintas divisi) | ✅ Siap | Manager lintas divisi dan Supervisor divisinya baru bisa *membuka* tugas di luar visibilitas lama setelah TASK-12 |
| Workflow status bersama oleh semua PIC | ✅ Siap | |
| Evidence (kirim, unduh) | ✅ Siap | Hapus evidence (TASK-18): tabel siap (soft delete, `deleted_by`), endpoint belum ada |
| Review Approve / Request Revision | ✅ Siap | Supervisor sebagai reviewer dan self-approval mengikuti TASK-17 (endpoint belum mengizinkan Supervisor) |
| Monitoring | ✅ Siap | Kunci metrik status berganti nama |
| Calendar & Timeline | ✅ Siap | Masih membaca `schedule_events`; `calendar_events` sudah ada tetapi belum punya endpoint (TASK-11/TASK-15) |
| Komentar & balasan | ⏳ Tabel siap | Endpoint dibuat di TASK-16 |
| Hapus Task Card | ⏳ Tabel siap (soft delete) | Endpoint belum ada (`DELETE /api/tasks/{id}` → `405`) |
| Akses baca global, permission per role | ⏳ Data siap (`meta.permissions`) | Penerapan di TASK-12 |
| Nonaktifkan akun | ⏳ Kolom `is_active` siap | Belum ada endpoint |

Uji end-to-end workflow yang bergantung pada TASK-12 sampai TASK-18 dilakukan setelah endpoint fitur tersebut tersedia.
