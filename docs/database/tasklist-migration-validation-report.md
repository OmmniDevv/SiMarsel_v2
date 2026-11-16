# Laporan Validasi Migration & Uji Integrasi Tasklist (TASK-19)

Jira: STEAM-272, bagian dari STEAM-268 (TASK-19). Tanggal uji: 16 Nov 2026.
Cakupan: 13 migration `2026_11_13_*` dari STEAM-269, STEAM-270, dan STEAM-271, beserta model, endpoint, dan data lama.
Rancangan dan prosedur deploy ada di [tasklist-schema-alignment.md](tasklist-schema-alignment.md); kontrak untuk FE di
[../api/tasklist-fe-integration.md](../api/tasklist-fe-integration.md).

## 1. Lingkungan

| Lingkungan | Dipakai untuk |
|---|---|
| SQLite in-memory (`phpunit.xml`) | Seluruh test suite |
| MySQL 8.4.3 (Laragon), database sementara `simarsel_test_tmp` | Test Task, Database, Account, dan Division dijalankan ulang di MySQL |
| MySQL 8.4.3, database sementara `simarsel_migration_check` | Prosedur deploy manual dengan data dummy format lama |
| MySQL 8.4.3, database dev lokal `simarsel` | Migrate, rollback, migrate ulang, dan `tasks:validate-migration` |

Staging tidak tersedia; sebagai gantinya dipakai **data dummy format lama** (`Database\Seeders\LegacyTasklistSeeder`) yang
meniru isi database sebelum TASK-19. Database sementara dihapus setelah uji.

## 2. Data dummy format lama

`LegacyTasklistSeeder` hanya mengisi kolom yang sudah ada sebelum TASK-19, jadi bisa di-seed pada schema lama:

- 3 role lama (ADM, MGR, EMP dengan nama "Employee"), 2 divisi, 6 akun.
- 6 task: satu per status lama, termasuk `ready_for_qa` dan `on_qa`; 5 punya `pic_id`, 1 Back Log tanpa PIC; versi
  1 sampai 9.
- 20 aktivitas: created, assigned, reassigned, status_changed dengan status lama, evidence_submitted,
  revision_requested, resubmitted, approved, entri dari akun yang sudah terhapus (hanya `actor_name_snapshot`), dan
  judul yang kebetulan berisi `on_qa`.
- 3 evidence (file dan link), 2 review (revisi siklus 1, approve), 1 agenda bersama dengan divisi tujuan.

## 3. Hasil migration pada data lama (MySQL)

Prosedur sama dengan deploy: rollback 13 migration TASK-19 → seed data lama → `migrate` → `RoleSeeder` →
`PermissionSeeder` → backfill ulang → `tasks:validate-migration` → rollback → migrate ulang.

| Langkah | Hasil |
|---|---|
| Data sebelum migrate | tasks=6, pic_id=5, status lama=2, activities=20, evidences=3, reviews=2, accounts=6 |
| `migrate` (13 migration) | Berhasil, 3,6 detik, tanpa konflik nama tabel/kolom/foreign key |
| Data setelah migrate | tasks=6, assignment aktif=5, status lama=0, activities=20, evidences=3, reviews=2, accounts=6 |
| `tasks:backfill-assignments --apply` (ulang) | 0 assignment dibuat (idempotent) |
| `tasks:validate-migration` | 14/14 PASS, exit 0 |
| `migrate:rollback --step=13` | Berhasil; status lama=2, pic_id=5, activities=20 kembali seperti semula |
| `migrate` ulang + validasi | Berhasil, exit 0 |

Pada database dev lokal: migrate, rollback, dan migrate ulang berhasil; `tasks:validate-migration` 14/14 PASS. Aturan
foreign key dicek langsung di `information_schema` (RESTRICT pada kolom riwayat, SET NULL pada `task_activities.account_id`).

## 4. Validasi per poin STEAM-272

### 4.1 Migration & data

| Poin | Bukti |
|---|---|
| Migration jalan tanpa konflik | Bagian 3; `TasklistMigrationIntegrationTest` |
| Backfill `pic_id` → `task_assignments` tanpa kehilangan relasi | `TasklistMigrationIntegrationTest::test_every_legacy_pic_becomes_one_active_assignment_with_its_origin` (`assigned_by`/`assigned_at` dari reassignment terakhir) |
| Migrate ulang tidak membuat assignment ganda | Backfill ulang = 0; `TaskMultiPicTest::test_backfill_adds_missing_assignments_once_and_never_restores_a_released_pic` |
| Kompatibilitas task, akun, role, evidence, review, histori | Jumlah baris sama sebelum dan sesudah; akun lama tetap role `EMP` (nama "Staff"), aktif; data lama terbaca lewat API (`test_existing_accounts_keep_their_role_and_legacy_data_works_through_the_api`) |
| Enam status dan histori QA lama terbaca | `test_statuses_and_their_history_are_renamed_and_read_with_the_new_labels` |
| Soft delete, foreign key, unique, optimistic locking | `TaskHistoryIntegrityTest`, `TasklistFoundationTest`, `TaskAssignmentTest::test_an_assignment_based_on_an_old_version_is_rejected` dan test `409` lain |
| Rollback | `test_rolling_back_restores_the_legacy_data_and_migrating_again_succeeds` |

### 4.2 Relasi model & query

| Poin | Bukti |
|---|---|
| Relasi model sesuai migration | `Task` (pics, assignments, collaboratingDivisions, comments, evidences, reviews, activities), `Account` (taskAssignments, assignedTasks, taskComments), `Division` (tasks, collaboratingTasks), `TaskComment`, `TaskAssignment`, `TaskDivision` |
| Banyak PIC aktif; tambah/ganti/lepas/lintas divisi tanpa hilang histori | `TaskAssignmentTest` (27 test), `TaskMultiPicTest` |
| `all_tasks` dan `my_tasks` | `TaskMultiPicTest::test_my_tasks_lists_only_tasks_the_account_is_an_active_pic_of` |
| Tanpa duplikasi di listing, pagination, filter, KPI | `TaskMultiPicTest::test_a_task_with_several_pics_is_listed_and_counted_once`, `test_sorting_by_pic_uses_the_first_active_pic_by_name` |
| Komentar/balasan, ownership evidence, histori review | `TaskHistoryIntegrityTest`, `TaskEvidenceTest`, `TaskReviewTest` |
| Calendar dan Timeline | `TaskCalendarTest`, `TaskScheduleTest`, plus agenda dan deadline data lama lewat `/api/tasks/calendar` |

### 4.3 Permission & workflow (`TaskRoleCompatibilityTest`)

| Poin | Hasil |
|---|---|
| Lima role tersedia | ADM, MGR, SPV, EMP, FRL; 7 permission `task.*` |
| Buat tugas | Admin dan Manager saja; Supervisor, Staff, Freelance `403` |
| Hapus Task Card | Belum ada endpoint (`405` untuk semua role); tabel sudah soft delete |
| Keputusan review | Admin dan Manager divisi; Supervisor, Staff, Freelance `403` |
| Atur PIC | Admin semua, Manager lintas divisi, Supervisor divisinya; Staff/Freelance `403` |
| Workflow bersama tanpa Lead PIC | PIC Freelance, Supervisor, dan Staff bergantian memindahkan status sampai Ready Review |
| Self-approval tidak dihalangi struktur DB | Tidak ada constraint reviewer ≠ PIC (`TaskHistoryIntegrityTest::test_every_review_decision_is_kept_and_a_pic_may_review_their_own_task`) |
| Histori utuh setelah revisi, ganti PIC, approve | `test_history_stays_complete_through_revision_pic_change_and_approval` |

Aturan yang bergantung pada TASK-12/13/16/17/18 (akses baca global, Supervisor sebagai reviewer, endpoint komentar dan
hapus evidence) belum diterapkan; test di atas mengunci perilaku saat ini supaya perubahan di ticket tersebut terlihat.

### 4.4 Kontrak API

Kontrak backend dibandingkan dengan kode FE di `dev`. Selisih dan status kesiapan per fitur ada di
[tasklist-fe-integration.md](../api/tasklist-fe-integration.md). Ringkasnya: status QA, `pic_id`/`pic`, opsi PIC, dan
kunci metrik monitoring perlu disesuaikan FE; komentar, hapus tugas/evidence, dan `calendar_events` belum punya endpoint.

Temuan saat uji: urutan key di `changes` histori berbeda antara MySQL (`{new, old}`) dan SQLite (`{old, new}`), sehingga
API mengirim bentuk yang berbeda tergantung database. Diperbaiki dengan `TaskActivity::orderedChanges()` yang dipakai
`description` dan `TaskActivityResource`: urutan field mengikuti label histori dan tiap field selalu `{ old, new }`.

## 5. Regression TASK-01 sampai TASK-11

| Suite | Hasil |
|---|---|
| Seluruh test, SQLite | 716 test: 701 lulus, 12 gagal (sudah gagal sebelum TASK-19), 3 dilewati (`PromoCodeConcurrencyTest`, butuh MySQL) |
| Task, Database, Account, Division, MySQL | 379/379 lulus |
| Frontend (`npm run test:frontend`) | 114/114 lulus (tidak ada perubahan FE) |

File regression fitur: `TaskCreationTest` (TASK-02), `TaskListingTest` (TASK-01/03), `TaskAssignmentTest` (TASK-04),
`TaskStatusTransitionTest` dan `TaskUpdateTest` (TASK-05), `TaskEvidenceTest` (TASK-06), `TaskReviewTest` (TASK-07),
`TaskActivityTest` (TASK-08), `TaskMonitoringTest` (TASK-09), `TaskScheduleTest` (TASK-10), `TaskCalendarTest` (TASK-11),
dan `TaskDetailTest`.

12 kegagalan yang sudah ada sebelumnya, tidak terkait TASK-19:

- `LoginTest` (2): password di `AccountSeeder` (`manager123`) berbeda dengan yang diharapkan test.
- `PerformanceExportTest` (2) dan `PromoAnalysisExportTest` (8): ekstensi PHP `zip` belum aktif di mesin uji
  (`Class "ZipArchive" not found`).

## 6. Validasi setelah deploy

```bash
php artisan tasks:validate-migration
```

Read-only, aman dijalankan di production. Exit code `0` bila tidak ada FAIL, `1` bila ada; bisa dipakai di skrip deploy.

| Pemeriksaan | Gagal bila |
|---|---|
| Migration TASK-19 sudah dijalankan | Ada migration `2026_11_13_*` yang belum jalan |
| Lima role tersedia | Salah satu dari ADM, MGR, SPV, EMP, FRL tidak ada |
| Permission Tasklist tersedia | Ada permission `task.*` hilang atau role tanpa permission |
| Tidak ada status QA lama di tasks | Masih ada `ready_for_qa`/`on_qa` |
| Semua status task dikenali | Ada status di luar enam status |
| Histori status memakai nama baru | `changes.status` masih memakai nama lama |
| PIC lama sudah di-backfill | Ada task dengan `pic_id` tanpa assignment sama sekali |
| Tidak ada PIC aktif ganda | Ada pasangan task/akun dengan lebih dari satu assignment aktif |
| Task di luar Back Log punya PIC aktif | Ada task aktif selain Back Log tanpa PIC |
| PIC aktif memenuhi syarat | WARN saja: PIC nonaktif atau bukan role PIC (data lama tetap sah) |
| Tidak ada data riwayat yatim | Ada baris riwayat yang `task_id`-nya tidak ada |
| Balasan komentar di task yang sama | Ada balasan ke komentar task lain |
| Divisi utama bukan kolaborator | Divisi utama tercatat di `task_divisions` |
| Foreign key riwayat RESTRICT | MySQL saja: kolom riwayat bukan RESTRICT |

## 7. Prosedur pemulihan

| Kegagalan | Tindakan |
|---|---|
| `migrate` gagal di tengah | Jalankan `php artisan migrate:status`, lalu `migrate:rollback --step=N` (N = jumlah migration TASK-19 yang sudah jalan). Semua migration punya `down()`. Bila rollback gagal, pulihkan dari backup `mysqldump`. |
| Validasi: role/permission | `php artisan db:seed --class=RoleSeeder --force` lalu `--class=PermissionSeeder --force` (aman diulang). |
| Validasi: PIC lama belum di-backfill | `php artisan tasks:backfill-assignments` (laporan), lalu `--apply`. Aman diulang; PIC yang sudah dilepas tidak dikembalikan. |
| Validasi: status QA lama tersisa | Rollback lalu jalankan ulang migration `2026_11_13_083919_rename_qa_statuses_to_review_statuses` (`migrate:rollback --path=...` lalu `migrate --path=...`), atau rollback penuh dan migrate ulang. |
| Validasi: data yatim, PIC ganda, balasan lintas task | Jangan ubah manual di production. Ekspor barisnya, laporkan, dan perbaiki lewat migration/command yang direview. |
| Aplikasi bermasalah setelah deploy | `php artisan migrate:rollback --step=13 --force`. `tasks.pic_id` tidak pernah diubah, jadi PIC lama utuh. Data yang baru ditulis ke tabel/kolom TASK-19 (assignment tambahan, komentar, agenda) ikut hilang; pulihkan dari backup bila perlu. |
| Rollback ditolak karena FK RESTRICT | Hanya terjadi bila ada penghapusan fisik yang tertunda; cek baris yang mereferensikan task/akun tersebut sebelum mengulang. |

Selalu ambil backup sebelum deploy (`mysqldump --single-transaction --routines <database>`).
