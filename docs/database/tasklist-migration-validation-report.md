# Laporan Validasi Migration & Uji Integrasi Tasklist (TASK-19)

Jira: STEAM-272, bagian dari STEAM-268 (TASK-19). Tanggal uji: 16 Nov 2026, diperbarui setelah revisi PR.
Cakupan: 15 migration TASK-19 (`2026_11_13_*` dan `2026_11_16_*`), pemindahan agenda ke `calendar_events`, aturan
akses per role, dan prosedur pemulihan. Rancangan dan prosedur deploy ada di
[tasklist-schema-alignment.md](tasklist-schema-alignment.md); kontrak untuk FE di
[../api/tasklist-fe-integration.md](../api/tasklist-fe-integration.md).

## 1. Lingkungan

| Lingkungan | Dipakai untuk |
|---|---|
| SQLite in-memory (`phpunit.xml`) | Seluruh test suite |
| MySQL 8.4.3 (Laragon), database sementara `simarsel_test_tmp` | Test Task, Database, Account, Auth, Division, dan Web dijalankan ulang di MySQL |
| MySQL 8.4.3, database sementara `simarsel_migration_check` | Prosedur deploy manual dengan data dummy format lama, pengaman rollback |

Staging tidak tersedia; sebagai gantinya dipakai **data dummy format lama** (`Database\Seeders\LegacyTasklistSeeder`) yang
meniru isi database sebelum TASK-19.

## 2. Data dummy format lama

`LegacyTasklistSeeder` hanya mengisi kolom yang sudah ada sebelum TASK-19, jadi bisa di-seed pada schema lama:

- 3 role lama (ADM, MGR, EMP dengan nama "Employee"), 2 divisi, 6 akun.
- 6 task: satu per status lama, termasuk `ready_for_qa` dan `on_qa`; 5 punya `pic_id`, 1 Back Log tanpa PIC; versi
  1 sampai 9.
- 20 aktivitas: created, assigned, reassigned, status_changed dengan status lama, evidence_submitted,
  revision_requested, resubmitted, approved, entri dari akun yang sudah terhapus (hanya `actor_name_snapshot`), dan
  judul yang kebetulan berisi `on_qa`.
- 3 evidence (file dan link), 2 review (revisi siklus 1, approve).
- 5 agenda dengan 4 baris divisi tujuan: agenda bersama (dengan divisi tujuan yang tidak berpengaruh), internal satu
  divisi, internal dua divisi dan beberapa hari, internal khusus Admin tanpa pembuat, dan agenda bersama yang terkait task.

## 3. Hasil migration pada data lama (MySQL)

Prosedur sama dengan deploy: rollback 15 migration TASK-19 → seed data lama → `migrate` → `RoleSeeder` →
`PermissionSeeder` → `tasks:validate-migration` → `tasks:recovery` → rollback → migrate ulang.

| Langkah | Hasil |
|---|---|
| Data sebelum migrate | tasks=6, activities=20, evidences=3, reviews=2, schedule_events=5, schedule_event_divisions=4, accounts=6 |
| `migrate` (15 migration) | Berhasil, 3,9 detik, tanpa konflik nama tabel/kolom/foreign key |
| Data setelah migrate | Semua jumlah di atas sama; assignment aktif=5, status lama=0, calendar_events=5, calendar_event_divisions=4 |
| `tasks:validate-migration` | 16/16 PASS, exit 0 (termasuk "Agenda lama tersalin" dan "Salinan agenda sama dengan aslinya: 5 sama") |
| `tasks:recovery` (status) | Semua 0: "Belum ada data baru. Rollback aman" |
| Tambah 1 komentar, lalu `migrate:rollback --step=15` | **Ditolak**: "Rollback TASK-19 dibatalkan karena akan menghapus data baru (Komentar tugas: 1)"; tabel dan komentar tetap ada |
| Hapus komentar uji, lalu `migrate:rollback --step=15` | Berhasil; status lama kembali (`ready_for_qa`, `on_qa`), `calendar_events` hilang, 5 agenda tetap di `schedule_events` |
| `migrate` ulang + seed + validasi | Berhasil, exit 0 |

## 4. Validasi per poin

### 4.1 Migration & data

| Poin | Bukti |
|---|---|
| Migration jalan tanpa konflik | Bagian 3; `TasklistMigrationIntegrationTest` |
| Backfill `pic_id` → `task_assignments` tanpa kehilangan relasi | `TasklistMigrationIntegrationTest::test_every_legacy_pic_becomes_one_active_assignment_with_its_origin` |
| Migrate ulang tidak membuat assignment ganda | `TaskMultiPicTest::test_backfill_adds_missing_assignments_once_and_never_restores_a_released_pic` |
| Kompatibilitas task, akun, role, evidence, review, histori | Jumlah baris sama sebelum dan sesudah; `test_existing_accounts_keep_their_role_and_legacy_data_works_through_the_api` |
| Enam status dan histori QA lama terbaca | `test_statuses_and_their_history_are_renamed_and_read_with_the_new_labels` |
| Soft delete, foreign key, unique, optimistic locking | `TaskHistoryIntegrityTest`, `TasklistFoundationTest`, test `409` di `TaskAssignmentTest` |
| Rollback sebelum ada data baru | `test_rolling_back_restores_the_legacy_data_and_migrating_again_succeeds`, `TasklistRecoveryTest::test_right_after_the_deploy_there_is_no_new_data_and_rolling_back_is_allowed` |

### 4.2 Agenda (`calendar_events`)

`TasklistMigrationIntegrationTest::test_every_agenda_moves_to_calendar_events_and_each_account_still_sees_the_same_agendas`:

| Poin | Hasil |
|---|---|
| Setiap agenda lama punya tepat satu salinan | 5 dari 5, id sama |
| Isi salinan | Judul, deskripsi, divisi penyelenggara, tanggal mulai/selesai, `is_shared`, `task_id`, pembuat, `created_at` sama |
| Divisi tujuan agenda internal | Sama per agenda |
| Akses agenda tidak berubah | Untuk Admin, Manager Marketing, Manager Finance, Staff Marketing, dan Staff Finance, daftar agenda di Calendar dan Timeline sama dengan hasil aturan lama yang dihitung langsung dari `schedule_events` |
| Agenda terkait task dan agenda beberapa hari | `task_id`, `start_date` 2026-11-20, `end_date` 2026-11-22 terbaca di Calendar |
| Regression Calendar/Timeline | `TaskCalendarTest`, `TaskScheduleTest` (38 test) lulus dengan sumber `calendar_events` |

### 4.3 Hak akses per role

Diuji di `TaskRoleCompatibilityTest`, `TaskListingTest`, `TaskAssignmentTest`, `TaskCreationTest`, `TaskReviewTest`,
`TaskUpdateTest`, `TaskStatusTransitionTest`, `TaskDetailTest`, `TaskEvidenceTest`, `TaskCalendarTest`,
`TaskScheduleTest`, `TaskMonitoringTest`, dan `LoginTest`:

| Poin | Hasil |
|---|---|
| Lima role membaca semua task lintas divisi | Daftar, detail, histori, evidence, review, Calendar, Timeline, monitoring: `200` untuk kelima role |
| Melihat tidak memberi hak mengubah | Task di luar kewenangan: `can_edit`/`can_assign`/`can_review` `false`, `editable_fields`/`next_statuses` kosong; PATCH, assignment, status, evidence, dan review lewat API `403` |
| Buat task | Admin dan Manajer semua divisi `201`; Supervisor divisinya `201`, divisi lain `422`; Staff dan Freelance `403` |
| Edit task | Admin dan Manajer lintas divisi; Supervisor hanya divisinya; Staff/Freelance non-PIC `403` |
| Atur PIC dan forward | Admin dan Manajer lintas divisi; Supervisor dari task divisinya (setelah diteruskan, task keluar dari cakupannya); Staff, Freelance, Supervisor divisi lain `403` |
| Review | Admin dan Manajer lintas divisi; Supervisor divisinya; Supervisor divisi lain, Staff, Freelance `403` |
| Self-approval | Supervisor yang PIC menyetujui task divisinya; Manajer yang PIC menyetujui task divisi lain; tercatat di review dan aktivitas `approved` |
| Workflow Multi-PIC | Setiap PIC aktif (Staff, Freelance, Supervisor) memindahkan status sampai Ready Review tanpa Lead PIC; non-PIC `403` |
| `all_tasks` / `my_tasks` | `all_tasks` semua task, `my_tasks` hanya task dengan PIC aktif; task multi-PIC tidak terduplikasi |
| PIC ditambah/dilepas | PIC yang dilepas tetap melihat task, hilang dari `my_tasks`, kehilangan hak status dan evidence (`403`) |
| Perubahan role | Staff yang diubah menjadi Supervisor langsung bisa mengatur PIC dan membuat task; histori task tidak berubah |
| Akun nonaktif | Login ditolak ("Akun Anda nonaktif. Hubungi Admin."); request Tasklist `403` |
| Hapus Task Card (TASK-12) | Admin dan Manajer semua divisi, Supervisor divisinya `200`; Staff/Freelance (termasuk PIC) dan Supervisor divisi lain `403`; tugas Done `422`; task terhapus hilang dari semua tampilan dan `404` (`TaskDeletionAndPreviewTest`) |
| Audit role (TASK-12) | Pembuatan akun dan setiap perubahan role tercatat di `account_role_changes`; histori task tidak berubah (`AccountRoleAuditTest`) |

### 4.4 Pemulihan setelah fitur dipakai

`TasklistRecoveryTest` (data lama → migrate → pengguna menambah PIC kedua, membuat task multi-PIC, memindahkan status,
menambah komentar, serta menambah, mengubah, dan menghapus agenda):

| Skenario | Hasil |
|---|---|
| Rollback setelah ada data baru | Ditolak `UnsafeTasklistRollbackException`; komentar, 2 PIC aktif, dan 6 agenda tetap ada |
| `tasks:recovery to-legacy` tanpa `--apply` | Tidak ada perubahan (dry run) |
| `tasks:recovery to-legacy --apply` | `tasks.pic_id` = PIC aktif paling awal; status kembali `ready_for_qa`/`on_qa`; `schedule_events` berisi agenda baru, agenda yang diubah, tanpa agenda yang dihapus, beserta divisi tujuannya; komentar dan assignment tetap ada |
| Aplikasi lama mengganti PIC, status, dan menambah agenda | Disimulasikan langsung di tabel lama |
| `tasks:recovery from-legacy --apply` | Status kembali ke nama baru; PIC yang diganti di aplikasi lama menjadi satu-satunya PIC aktif; task multi-PIC yang tidak diubah tetap punya 2 PIC; agenda dari aplikasi lama masuk `calendar_events`; validasi lulus; API detail benar |
| Menjalankan `from-legacy` lagi | 0 perubahan |

### 4.5 Kontrak API

Kontrak backend dibandingkan dengan kode FE di `dev`. Selisih, dependensi, dan urutan penerapan ada di
[tasklist-fe-integration.md](../api/tasklist-fe-integration.md). Calendar dan Timeline tidak berubah bentuk.

Temuan sebelumnya: urutan key di `changes` histori berbeda antara MySQL dan SQLite; diperbaiki dengan
`TaskActivity::orderedChanges()`.

## 5. Regression

| Suite | Hasil |
|---|---|
| Seluruh test, SQLite (setelah sinkron dengan `dev` #117) | 720 test: 705 lulus, 12 gagal (sudah gagal sebelum TASK-19), 3 dilewati (`PromoCodeConcurrencyTest`, butuh MySQL) |
| Task dan Database, MySQL | 354/354 lulus |
| Account, Auth, Division, Web, MySQL | 116 test: 114 lulus, 2 gagal (`LoginTest`, sudah gagal sebelumnya) |
| Frontend (`node --test tests/Frontend`) | 141/141 lulus (tidak ada perubahan FE) |

12 kegagalan yang sudah ada sebelumnya, tidak terkait TASK-19:

- `LoginTest` (2): password di `AccountSeeder` (`manager123`) berbeda dengan yang diharapkan test.
- `PerformanceExportTest` (2) dan `PromoAnalysisExportTest` (8): ekstensi PHP `zip` belum aktif di mesin uji
  (`Class "ZipArchive" not found`).

## 6. Validasi setelah deploy

```bash
php artisan tasks:validate-migration
php artisan tasks:recovery
```

Keduanya read-only. `tasks:validate-migration` keluar dengan exit code `0` bila tidak ada FAIL, `1` bila ada.

| Pemeriksaan | Gagal bila |
|---|---|
| Migration TASK-19 sudah dijalankan | Ada migration `2026_11_13_*`/`2026_11_16_*` yang belum jalan |
| Lima role tersedia | Salah satu dari ADM, MGR, SPV, EMP, FRL tidak ada |
| Permission Tasklist tersedia | Ada permission `task.*` hilang atau role tanpa permission |
| Tidak ada status QA lama di tasks | Masih ada `ready_for_qa`/`on_qa` |
| Semua status task dikenali | Ada status di luar enam status |
| Histori status memakai nama baru | `changes.status` masih memakai nama lama |
| PIC lama sudah di-backfill | Ada task dengan `pic_id` tanpa assignment sama sekali |
| Tidak ada PIC aktif ganda | Ada pasangan task/akun dengan lebih dari satu assignment aktif |
| Task di luar Back Log punya PIC aktif | Ada task aktif selain Back Log tanpa PIC |
| PIC aktif memenuhi syarat | WARN saja: PIC nonaktif atau bukan role PIC |
| Tidak ada data riwayat yatim | Ada baris riwayat yang `task_id`-nya tidak ada |
| Balasan komentar di task yang sama | Ada balasan ke komentar task lain |
| Divisi utama bukan kolaborator | Divisi utama tercatat di `task_divisions` |
| Foreign key riwayat RESTRICT | MySQL saja: kolom riwayat bukan RESTRICT |
| Agenda lama tersalin ke `calendar_events` | Ada agenda `schedule_events` tanpa salinan |
| Salinan agenda sama dengan aslinya | Salinan yang belum diubah berbeda isi, tanggal, `is_shared`, atau divisi tujuan dari aslinya |

## 7. Prosedur pemulihan

Selalu ambil backup sebelum deploy (`mysqldump --single-transaction --routines <database>`), lalu tentukan kondisinya
dengan `php artisan tasks:recovery`.

### 7.1 Sebelum ada data baru

| Kegagalan | Tindakan |
|---|---|
| `migrate` gagal di tengah | `php artisan migrate:status`, lalu `migrate:rollback --step=N` (N = jumlah migration TASK-19 yang sudah jalan). Bila rollback gagal, pulihkan dari backup. |
| Validasi: role/permission | `php artisan db:seed --class=RoleSeeder --force` lalu `--class=PermissionSeeder --force` (aman diulang). |
| Validasi: PIC lama belum di-backfill | `php artisan tasks:backfill-assignments`, lalu `--apply`. |
| Validasi: status QA lama tersisa | Rollback lalu jalankan ulang migration `2026_11_13_083919_rename_qa_statuses_to_review_statuses`, atau rollback penuh dan migrate ulang. |
| Validasi: agenda belum tersalin atau berbeda | Rollback migration `2026_11_16_090000_*` lalu migrate ulang; penyalinan memakai `legacy_schedule_event_id` sehingga tidak menggandakan agenda. |
| Aplikasi bermasalah setelah deploy | `php artisan migrate:rollback --step=15 --force`, lalu deploy kode versi sebelumnya. |

### 7.2 Setelah fitur dipakai

Rollback skema akan menghapus data yang tidak ada di `tasks.pic_id` maupun di backup sebelum deploy, sehingga ditolak
oleh `2026_11_16_090100_guard_tasklist_rollback`. Pulihkan dengan mempertahankan skema dan data terbaru:

1. `php artisan tasks:recovery to-legacy` (laporan), lalu `--apply`.
2. Deploy kode versi sebelumnya. Tabel baru tetap ada dan tidak dibaca aplikasi lama.
3. Saat kode TASK-19 dipasang lagi: `php artisan tasks:recovery from-legacy --apply`, lalu
   `php artisan tasks:validate-migration`.

| Masalah lain | Tindakan |
|---|---|
| Validasi: data yatim, PIC ganda, balasan lintas task | Jangan ubah manual di production. Ekspor barisnya, laporkan, dan perbaiki lewat migration/command yang direview. |
| Penghapusan fisik ditolak FK RESTRICT | Cek baris riwayat yang mereferensikan task/akun tersebut; riwayat tidak boleh ikut terhapus. |
| Data baru memang boleh dibuang (database uji) | `TASKLIST_ALLOW_DESTRUCTIVE_ROLLBACK=true` lalu rollback. Jangan dipakai di production. |
