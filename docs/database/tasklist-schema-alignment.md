# Tasklist Database Foundation — Schema Alignment

Jira: STEAM-268 (TASK-19), subtask STEAM-269 — *DB: Existing Schema Alignment & Migration Consolidation*.
Acuan: ERD To-Be SimMarsel Tasklist v1.0 (https://dbdiagram.io/d/TASKLIST-6aebf555f1d298b5736edc37).
Baseline: migration dan model di `dev` per 13 Nov 2026 (TASK-01 sampai TASK-11).

Seluruh perubahan bersifat **aditif**: tidak ada tabel atau kolom existing yang di-drop atau di-rename. Perubahan nilai
data hanya penamaan status QA (bagian 3) dan penyalinan agenda ke `calendar_events` (bagian 5a). Revisi PR menambahkan
pemindahan agenda, penerapan aturan akses (bagian 5b), dan pengaman rollback (bagian 8).

## 1. As-Is vs To-Be

| Entitas | As-Is (sebelum) | To-Be (ERD) | Perubahan |
|---|---|---|---|
| `roles` | ADM, MGR, EMP; `name` varchar(25) | + SPV, FRL; `name` varchar(50) | Kolom dilebarkan; SPV dan FRL ditambah lewat `RoleSeeder`. EMP tetap, nama tampil menjadi **Staff** |
| `permissions` | Tidak ada; aturan akses di `Task`/`TaskPolicy` | Ada | Tabel baru + `PermissionSeeder` |
| `role_permissions` | Tidak ada | PK (role_id, permission_id) | Tabel baru |
| `divisions` | `name` varchar(25) unique | varchar(100) | Kolom dilebarkan; unique tetap |
| `accounts` | Tanpa indikator aktif | `is_active` | Kolom baru, default `true` untuk semua akun existing |
| `platforms` | Sesuai ERD | — | Tidak berubah |
| `task_categories` | Sesuai ERD | — | Tidak berubah |
| `tasks` | `pic_id`, tanpa soft delete | `deleted_at`, `deleted_by`, index tambahan | Soft delete + index `(status, deadline)`, `start_date`, `deleted_at`. `pic_id` **dipertahankan** |
| `task_assignments` | Tidak ada (1 PIC di `tasks.pic_id`) | Multi-PIC | Tabel baru + backfill dari `pic_id` |
| `task_divisions` | Tidak ada | Divisi kolaborator | Tabel baru |
| `task_evidences` | Tanpa metadata file / soft delete | `mime_type`, `file_size`, `deleted_at`, `deleted_by` | Kolom baru (nullable) |
| `task_reviews` | `cycle`, `decision` varchar(20), tanpa `updated_at` | `decision` varchar(30), `updated_at` | Tidak diubah (lihat pemetaan) |
| `task_comments` | Tidak ada | Komentar + balasan | Tabel baru |
| `task_activities` | `action` varchar(30), `actor_name_snapshot` | `action` varchar(50) | Kolom `action` dilebarkan |
| `calendar_events` | Agenda di `schedule_events` (+ `schedule_event_divisions`) | `calendar_events` | Tabel baru, menjadi **satu-satunya sumber agenda**; ditambah `is_shared`, `task_id`, `legacy_schedule_event_id`, `creator_id` nullable, dan `calendar_event_divisions` (bagian 5a) |

## 2. Pemetaan nama field / nilai

| ERD / kontrak | Implementasi | Catatan |
|---|---|---|
| Role "Manajer" | `MGR`, nama `Manager` | Kode existing dipertahankan |
| Role "Staff" | `EMP`, nama `Staff` | Kode existing dipertahankan agar akun tidak berubah role |
| Status Back Log / To Do / On Process | `backlog` / `todo` / `in_progress` | Tidak berubah |
| Status Ready Review / On Review | `ready_review` / `on_review` | Sebelumnya `ready_for_qa` / `on_qa` |
| Status Done | `done` | Tidak berubah |
| `task_reviews.decision` approved / revision_requested | `approve` / `revision` | Nilai existing dipertahankan, sesuai enum `TaskReviewDecision` |
| `task_reviews.updated_at` | — | Review bersifat append-only (satu baris per keputusan), jadi tidak perlu `updated_at` |
| — | `task_reviews.cycle` | Field existing di luar ERD, dipertahankan |
| — | `task_activities.actor_name_snapshot` | Field existing di luar ERD, dipertahankan |
| — | `task_assignments.active_flag` | Kolom generated untuk unique PIC aktif (bagian 4) |
| Metrik monitoring `ready_for_qa` / `on_qa` | `ready_review` / `on_review` | Kunci response `TaskRepository::STATUS_METRICS` ikut diganti |

## 3. Urutan migration dan dependensi

| # | Migration | Bergantung pada | Jenis |
|---|---|---|---|
| 1 | `2026_11_13_083909_widen_name_columns_on_roles_and_divisions_tables` | roles, divisions | Ubah panjang kolom |
| 2 | `2026_11_13_083911_add_is_active_to_accounts_table` | accounts | Tambah kolom |
| 3 | `2026_11_13_083913_create_permissions_table` | — | Tabel baru |
| 4 | `2026_11_13_083915_create_role_permissions_table` | #3, roles | Tabel baru |
| 5 | `2026_11_13_083917_add_soft_delete_and_indexes_to_tasks_table` | tasks, accounts | Tambah kolom + index |
| 6 | `2026_11_13_083919_rename_qa_statuses_to_review_statuses` | tasks, task_activities | **Data**: ganti nilai status |
| 7 | `2026_11_13_083921_create_task_assignments_table` | tasks, accounts, task_activities | Tabel baru + **backfill** |
| 8 | `2026_11_13_083922_create_task_divisions_table` | tasks, divisions | Tabel baru |
| 9 | `2026_11_13_083924_add_file_metadata_and_soft_delete_to_task_evidences_table` | task_evidences | Tambah kolom |
| 10 | `2026_11_13_083926_create_task_comments_table` | tasks, accounts | Tabel baru |
| 11 | `2026_11_13_083928_widen_action_on_task_activities_table` | task_activities | Ubah panjang kolom |
| 12 | `2026_11_13_083929_create_calendar_events_table` | divisions, accounts | Tabel baru |
| 13 | `2026_11_13_105301_protect_task_history_from_physical_deletes` | #7, #10, #12, task_activities, task_evidences, task_reviews | Ubah foreign key ke RESTRICT + index `calendar_events` |
| 14 | `2026_11_16_090000_move_schedule_events_to_calendar_events` | #12, schedule_events, schedule_event_divisions | Tambah kolom + tabel `calendar_event_divisions` + **salin agenda** |
| 15 | `2026_11_16_090100_guard_tasklist_rollback` | #1–#14 | Tidak mengubah apa pun saat migrate; menolak rollback bila sudah ada data baru |

Prasyarat: semua migration sebelumnya sudah jalan, termasuk `2026_11_05_*`, `2026_11_06_*`, dan `2026_11_09_*`.

### Migrasi status (#6)

- `tasks.status`: `ready_for_qa` → `ready_review`, `on_qa` → `on_review`.
- `task_activities.changes`: nilai di bawah kunci `status` (`old`/`new`) diganti dengan pemetaan yang sama, supaya
  riwayat lama dan baru terbaca konsisten. Kunci lain (misalnya judul yang kebetulan berisi `on_qa`) tidak disentuh.
- Ini satu-satunya penulisan ulang `task_activities` yang disengaja. Aplikasi tetap memperlakukan riwayat sebagai
  append-only (model `TaskActivity` menolak update/delete); migration memakai query builder.
- `down()` mengembalikan kedua tabel ke nilai lama.

### Backfill PIC (#7)

- Setiap `tasks.pic_id` yang terisi menjadi satu baris `task_assignments` yang aktif.
- `assigned_by` dan `assigned_at` diambil dari riwayat `assigned`/`reassigned` terakhir pada task tersebut. Jika tidak
  ada riwayat, dipakai `creator_id` dan `created_at` task.
- Memakai `insertOrIgnore`, sehingga aman bila dijalankan ulang.
- `tasks.pic_id` **tidak diubah dan tidak dihapus**. Sejak STEAM-270 backend membaca dan menulis PIC hanya lewat
  `task_assignments`; `pic_id` dibiarkan sebagai data lama. Kolom ini baru di-drop dengan migration terpisah setelah FE
  memakai kontrak `pic_ids`/`pics` dan tidak ada lagi pembaca `pic_id`.
- Backfill bisa dijalankan ulang lewat `php artisan tasks:backfill-assignments` (laporan) dan
  `php artisan tasks:backfill-assignments --apply`. Hanya task yang punya `pic_id` dan **belum punya baris assignment
  sama sekali** yang diisi, sehingga perintah ini idempotent dan tidak pernah mengembalikan PIC yang sudah dilepas lewat
  API multi-PIC. Laporan berisi `tasks_with_pic`, `matched` (pic_id = PIC aktif), `missing` (akan di-backfill),
  `diverged` (PIC sudah diubah lewat API baru), dan `active_assignments`.

## 4. Constraint penting

- **PIC aktif unik per task**: `task_assignments.active_flag` adalah kolom generated
  (`CASE WHEN unassigned_at IS NULL THEN 1 END`) dengan unique index `(task_id, account_id, active_flag)`. Karena unique
  index mengizinkan banyak NULL, riwayat assignment yang sudah dilepas tetap tersimpan, tetapi akun yang sama tidak
  bisa menjadi PIC aktif dua kali (MySQL error 1062).
- **Riwayat tidak ikut terhapus permanen (STEAM-271)**: migration
  `2026_11_13_105301_protect_task_history_from_physical_deletes` mengubah foreign key berikut dari CASCADE menjadi
  **RESTRICT**, sehingga penghapusan fisik yang akan ikut menghapus riwayat ditolak database:

  | Tabel | Kolom | ON DELETE |
  |---|---|---|
  | `task_activities`, `task_evidences`, `task_reviews` | `task_id` | RESTRICT |
  | `task_assignments` | `task_id`, `account_id` | RESTRICT |
  | `task_comments` | `task_id`, `author_id`, `parent_comment_id` | RESTRICT |
  | `calendar_events` | `creator_id` | RESTRICT (sejak dibuat) |
  | Kolom pelaku: `task_activities.account_id`, `assigned_by`, `unassigned_by`, `deleted_by`, `added_by`, `submitter_id`, `reviewer_id`, `tasks.creator_id` | | SET NULL (tidak berubah) |

  ON UPDATE memakai default (primary key tidak pernah diubah). `task_divisions` tetap CASCADE ke `tasks` karena berisi
  relasi kolaborasi, bukan riwayat.
- **Akun yang punya riwayat tugas tidak bisa dihapus**: `AccountService::delete()` menolaknya dengan
  `AccountHasTaskHistoryException` (422) dan menyarankan menonaktifkan akun (`accounts.is_active = false`). Pengecekan
  ada di `AccountRepository::hasTaskHistory()` dan mencakup baris yang sudah di-soft delete. Karena itu kolom SET NULL
  di atas pada praktiknya tidak pernah terpicu; `task_activities.actor_name_snapshot` tetap dipakai untuk akun yang
  terhapus sebelum aturan ini ada.
- **Soft delete**: `tasks`, `task_evidences`, `task_comments`, dan `calendar_events`. `task_comments.deleted_at` sudah
  disiapkan tetapi belum dipakai sampai fitur hapus komentar disetujui.
- **Balasan komentar**: `TaskComment` menolak balasan yang `parent_comment_id`-nya berasal dari task lain
  (`CommentReplyOutsideTaskException`, 422). Komentar tidak mengubah status task dan bukan keputusan review.
- **Review**: setiap keputusan (Approve/Request Revision) adalah baris baru dengan `cycle`, `reviewer_id`, `created_at`,
  dan `note`. Tidak ada constraint yang melarang reviewer sekaligus PIC (self-approval Manajer/Supervisor diizinkan).
- **Index Calendar**: `calendar_events` memakai index `(start_at, end_at)` untuk query rentang tanggal Calendar dan
  Timeline.

## 5. Multi-PIC dan relasi lintas divisi (STEAM-270)

- **Sumber PIC**: `task_assignments`. Visibilitas Staff/Freelance/Supervisor, hak menjalankan workflow, hak mengirim
  evidence, filter `my_tasks`, dan sort PIC membaca assignment aktif.
- **Tanpa duplikasi Task Card**: semua filter PIC memakai `whereHas`/subquery (bukan join), sehingga listing,
  pagination, `status_counts`, dan KPI monitoring menghitung satu tugas sekali walaupun PIC-nya banyak.
- **Konkurensi**: perubahan PIC mengunci baris `tasks` (`lockForUpdate`) dan memeriksa `version`; unique index
  `task_assignments_active_pic_unique` menjadi pengaman terakhir.
- **Divisi kolaborator**: pivot `TaskDivision` menolak divisi utama (`PrimaryDivisionCollaboratorException`, 422).
  Saat tugas dipindah ke divisi lain, divisi tujuan dihapus dari daftar kolaborator. Belum ada endpoint untuk mengelola
  divisi kolaborator.

### 5a. Agenda: `calendar_events` sebagai satu-satunya sumber (revisi PR)

- Migration #14 melengkapi `calendar_events` dengan yang sebelumnya disimpan `schedule_events`:

  | `schedule_events` | `calendar_events` | Catatan |
  |---|---|---|
  | `id` | `id` | Dipertahankan bila belum dipakai, sehingga id agenda di API tidak berubah |
  | `owner_division_id` | `division_id` | Divisi penyelenggara; tidak menentukan siapa yang boleh melihat |
  | `start_date`, `end_date` (date) | `start_at`, `end_at` (datetime 00:00) | API tetap mengirim `start_date`/`end_date` (YYYY-MM-DD) |
  | `is_shared` | `is_shared` | Kolom baru, default `true` |
  | `task_id` | `task_id` | Kolom baru, FK `tasks` SET NULL (sama seperti sebelumnya) |
  | `creator_id` (nullable) | `creator_id` | Dijadikan nullable karena agenda lama boleh tanpa pembuat |
  | `schedule_event_divisions` | `calendar_event_divisions` | Divisi tujuan agenda internal |
  | — | `legacy_schedule_event_id` (unique) | Penanda asal salinan, untuk validasi dan pemulihan |
  | `created_at`, `updated_at` | sama | Disalin apa adanya |

- **Aturan akses agenda tidak berubah**: agenda bersama (`is_shared = true`) terlihat oleh semua; agenda internal hanya
  oleh Admin dan akun divisi tujuannya; agenda internal tanpa divisi tujuan hanya oleh Admin. Fokus divisi di Calendar/
  Timeline tetap mencakup agenda yang diselenggarakan atau ditujukan ke divisi itu, plus agenda bersama tanpa
  penyelenggara.
- **Pembaca dan penulis**: `TaskCalendarService`, `TaskScheduleService`, `CalendarEventResource`, `ScheduleItemResource`,
  `CalendarEventFactory`, dan `CalendarEventSeeder` memakai `CalendarEvent`. Model `ScheduleEvent`, factory, dan seeder
  lamanya dihapus. Belum ada endpoint tulis agenda (TASK-11/TASK-15).
- **`schedule_events` tidak dihapus** dan tidak lagi dibaca atau ditulis aplikasi, kecuali oleh prosedur pemulihan
  (bagian 8). Tabel ini di-drop dengan migration terpisah setelah hasil pemindahan diverifikasi di production
  (`tasks:validate-migration`: "Agenda lama tersalin" dan "Salinan agenda sama dengan aslinya" PASS).
- Kontrak API Calendar dan Timeline **tidak berubah**: bentuk `events`/`data` sama, id agenda sama.

### 5b. Aturan akses (revisi PR, acceptance criteria TASK-19/TASK-12)

Diterapkan di `Task` (`scopeVisibleTo`, `isVisibleTo`, `managedBy`, `assignableBy`, `reviewableBy`, `updatableBy`),
`TaskPolicy`, dan `Division::scopeTaskCreatableBy`. Setiap aksi memeriksa **permission role** (`role_permissions`),
**cakupan divisi**, dan **assignment aktif** (`task_assignments.unassigned_at IS NULL`). Tidak ada mekanisme permission
kedua.

| Aksi | Admin | Manajer | Supervisor | Staff | Freelance |
|---|---|---|---|---|---|
| Melihat daftar dan detail semua task (Board, Table, Calendar, Timeline, histori, evidence, review) | ✓ | ✓ | ✓ | ✓ | ✓ |
| Membuat task | Semua divisi | Semua divisi | Divisinya | ✗ (403) | ✗ (403) |
| Mengubah field task | Semua divisi | Semua divisi | Divisinya | ✗ | ✗ |
| Mengatur PIC dan meneruskan ke divisi lain | Semua divisi | Semua divisi | Task divisinya | ✗ (403) | ✗ (403) |
| Back Log → To Do, Ready Review → On Review | Semua divisi | Semua divisi | Divisinya | ✗ | ✗ |
| To Do → On Process → Ready Review, kirim evidence | Bila PIC aktif | Bila PIC aktif | Bila PIC aktif | Bila PIC aktif | Bila PIC aktif |
| Review (Approve/Request Revision), termasuk self-approval | Semua divisi | Semua divisi | Divisinya | ✗ (403) | ✗ (403) |

- Akun nonaktif (`is_active = false`) tidak bisa login dan tidak melihat apa pun (`403`).
- Melihat tidak memberi hak mengubah: task di luar kewenangan dikirim dengan `can_edit`, `can_assign`, `can_review`
  bernilai `false` dan `editable_fields`, `next_statuses` kosong. Perubahan lewat API ditolak `403`.
- PIC yang dilepas tetap melihat task lewat `all_tasks`, tetapi hilang dari `my_tasks` dan kehilangan hak PIC.
- Perubahan role berlaku pada request berikutnya (permission dibaca dari database) tanpa mengubah histori task.
- Hapus Task Card (TASK-12): `DELETE /api/tasks/{id}`, soft delete oleh Admin/Manajer (semua divisi) dan Supervisor (divisinya), kecuali tugas Done.
- Perubahan role akun dicatat di `account_role_changes` (TASK-12, migration `2026_11_16_142438_create_account_role_changes_table`). Rollback migration itu ditolak bila audit sudah berisi data, kecuali `TASKLIST_ALLOW_DESTRUCTIVE_ROLLBACK=true`.

## 6. Hal yang belum dikerjakan

- **FE** masih memakai kontrak lama (`pic_id`/`pic`, status QA lama) dan perlu disesuaikan **sebelum** perubahan ini
  dipakai pengguna. Lihat [../api/tasklist-fe-integration.md](../api/tasklist-fe-integration.md).
- **Komentar, hapus evidence, endpoint tulis agenda, nonaktifkan akun, pulihkan task terhapus, baca audit role**: tabel/kolom
  siap, endpoint di ticket lain.
- **Drop `schedule_events` dan `tasks.pic_id`**: setelah verifikasi production dan FE tidak lagi memakai kontrak lama.
- **Catatan kerja PIC (TASK-13)**: masih PENDING di ERD, menunggu keputusan SA.
- **Divisi yang dikelola Supervisor**: sementara memakai `accounts.division_id`.

## 7. Migration dari branch lama yang belum di-merge

Branch berikut membuat ulang migration yang **sudah ada** di `dev` dengan nama file berbeda (`2026_11_01_00000x_*`):
`feat/task-evidence-storage-submission-validation`, `feat/task-monitoring-aggregation-calculation`,
`feat/task-review-revision-workflow`, `feat/task-timeline-shared-schedule`.

Isinya (task_evidences, evidence_required, task_reviews, `tasks.is_shared`) sudah ada di `dev` atau tidak dipakai lagi
(`is_shared` diganti visibilitas global). **Jangan merge migration dari branch tersebut**, karena tabelnya akan dibuat
dua kali dan migration gagal.

## 8. Prosedur deploy, validasi, dan rollback

### Sebelum migrate

```bash
mysqldump -u <user> -p --single-transaction --routines <database> > backup-before-tasklist-foundation.sql
php artisan migrate:status      # pastikan hanya 15 migration 2026_11_13_* dan 2026_11_16_* (dan prasyaratnya) yang Pending
```

Catat jumlah baris sebagai pembanding:

```sql
SELECT COUNT(*) FROM tasks;
SELECT COUNT(*) FROM tasks WHERE pic_id IS NOT NULL;
SELECT status, COUNT(*) FROM tasks GROUP BY status;
SELECT COUNT(*) FROM task_activities;
SELECT COUNT(*) FROM accounts;
SELECT COUNT(*) FROM schedule_events;
```

### Migrate dan seed

```bash
php artisan migrate --force
php artisan db:seed --class=RoleSeeder --force
php artisan db:seed --class=PermissionSeeder --force
```

### Validasi

```bash
php artisan tasks:validate-migration   # 16 pemeriksaan, exit 0 bila lulus
```

Atau manual:

```sql
-- jumlah assignment aktif = jumlah task yang punya PIC
SELECT COUNT(*) FROM task_assignments WHERE unassigned_at IS NULL;
-- tidak ada lagi status QA lama
SELECT COUNT(*) FROM tasks WHERE status IN ('ready_for_qa', 'on_qa');
-- jumlah task, aktivitas, dan akun sama dengan sebelum migrate
SELECT COUNT(*) FROM tasks;
SELECT COUNT(*) FROM task_activities;
SELECT COUNT(*) FROM accounts;
-- 5 role, Admin/Manager/Supervisor 7 permission, Staff/Freelance 3
SELECT r.code_role, COUNT(rp.permission_id) FROM roles r LEFT JOIN role_permissions rp ON rp.role_id = r.id GROUP BY r.code_role;
-- setiap agenda lama punya salinan
SELECT COUNT(*) FROM schedule_events s LEFT JOIN calendar_events c ON c.legacy_schedule_event_id = s.id WHERE c.id IS NULL;
```

### Rollback dan pemulihan

Ada dua kondisi yang prosedurnya berbeda. Cek dulu kondisinya:

```bash
php artisan tasks:recovery           # sama dengan: tasks:recovery status
```

**A. Belum ada data baru** (laporan semua 0, biasanya segera setelah deploy): rollback skema aman.

```bash
php artisan migrate:rollback --step=15 --force
```

15 adalah jumlah migration TASK-19. Bila sudah ada migration lain setelahnya (misalnya audit role TASK-12), angkanya
bertambah; `tasks:recovery` menampilkan angka yang tepat untuk database tersebut.

Semua migration punya `down()`. Status lama kembali di `tasks` dan `task_activities`, tabel/kolom baru dihapus, salinan
agenda dihapus (aslinya masih di `schedule_events`), dan `tasks.pic_id` utuh karena tidak pernah diubah.

**B. Sudah ada data baru** (PIC tambahan atau dilepas, task baru, komentar, divisi kolaborator, agenda baru/diubah/
dihapus, task/evidence yang di-soft delete, akun nonaktif): **jangan rollback skema**. Data tersebut tidak ada di
`tasks.pic_id` maupun di backup sebelum deploy, jadi rollback akan menghilangkannya. Migration
`2026_11_16_090100_guard_tasklist_rollback` menolak rollback pada kondisi ini (`UnsafeTasklistRollbackException`).

Yang dilakukan adalah mengembalikan **kode aplikasi** ke versi sebelumnya dengan skema tetap, setelah data terbaru
disalin ke struktur lama:

```bash
php artisan tasks:recovery to-legacy           # laporan (dry run)
php artisan tasks:recovery to-legacy --apply   # lalu deploy kode versi sebelumnya
```

`to-legacy` mengisi `tasks.pic_id` dengan PIC aktif paling awal, mengembalikan nama status lama di `tasks` dan
`task_activities`, serta menyalin agenda terbaru ke `schedule_events` (baru, diubah, dan dihapus). Tabel baru tidak
disentuh, jadi PIC tambahan, komentar, dan agenda tetap tersimpan.

Saat kode TASK-19 dipasang lagi:

```bash
php artisan tasks:recovery from-legacy --apply
php artisan tasks:validate-migration
```

`from-legacy` mengembalikan nama status baru, mengambil PIC yang diganti di aplikasi lama (`tasks.pic_id` yang bukan
PIC aktif: PIC aktif lain dilepas, akun itu menjadi PIC), dan menyalin agenda dari `schedule_events`. Task multi-PIC
yang tidak diubah di aplikasi lama tetap memiliki semua PIC-nya. Keduanya aman dijalankan ulang.

Keterbatasan selama aplikasi lama berjalan: task yang di-soft delete akan terlihat lagi (aplikasi lama tidak mengenal
`deleted_at`), hanya satu PIC per task yang terlihat, dan penghapusan fisik yang menyentuh riwayat ditolak foreign key
RESTRICT. Membuang data baru dengan sengaja (misalnya di database uji) hanya bisa dengan
`TASKLIST_ALLOW_DESTRUCTIVE_ROLLBACK=true`.

### Hasil uji

Hasil lengkap per 16 Nov 2026 ada di [tasklist-migration-validation-report.md](tasklist-migration-validation-report.md).
Uji awal (13 Nov 2026):

- SQLite (test suite): `tests/Feature/Database/TasklistFoundationTest.php` — backfill PIC, unique PIC aktif, riwayat
  assignment, migrasi status + rollback, soft delete, komentar/balasan, divisi kolaborator, seeder role dan permission.
- MySQL 8.4 (database percobaan terpisah dengan data bentuk lama): migrate → validasi → rollback dengan data → migrate
  ulang. Status, riwayat, dan backfill sesuai; duplikat PIC aktif ditolak (error 1062); rollback mengembalikan data
  persis seperti sebelum migrate.
