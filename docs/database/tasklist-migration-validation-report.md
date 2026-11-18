# Laporan Validasi Migration & Uji Integrasi Tasklist (TASK-19)

Jira: STEAM-272, bagian dari STEAM-268 (TASK-19). Tanggal uji: 17 Nov 2026, setelah revisi PR ketiga (skema mengikuti
ERD; aturan akses dan divisi tujuan agenda dipertahankan). Cakupan: 17 migration TASK-19 (`2026_11_13_*` dan `2026_11_16_*`), kecocokan skema dengan ERD, pemindahan
agenda ke `calendar_events`, aturan akses per role, dan prosedur pemulihan. Rancangan dan prosedur deploy ada di
[tasklist-schema-alignment.md](tasklist-schema-alignment.md); ERD acuan di
[tasklist-erd-to-be-v1.dbml](tasklist-erd-to-be-v1.dbml); kontrak untuk FE di
[../api/tasklist-fe-integration.md](../api/tasklist-fe-integration.md).

## 1. Lingkungan

| Lingkungan | Dipakai untuk |
|---|---|
| SQLite in-memory (`phpunit.xml`) | Seluruh test suite |
| MySQL 8.4.3 (Laragon), database sementara `simarsel_test_tmp` | Test Task, Database, Account, Auth, Division, dan Web dijalankan ulang di MySQL |
| MySQL 8.4.3, database sementara `simarsel_migration_check` | Prosedur deploy manual dengan data dummy format lama, pengaman rollback, introspeksi skema fisik |

Staging tidak tersedia; sebagai gantinya dipakai **data dummy format lama** (`Database\Seeders\LegacyTasklistSeeder`) yang
meniru isi database sebelum TASK-19.

## 2. Kecocokan skema dengan ERD

ERD To-Be v1.0 dibandingkan dengan skema **fisik** hasil migration (bukan dengan file migration):
`migrate:fresh` di MySQL lalu `information_schema` (kolom, tipe, nullable, index, foreign key). Pemeriksaan yang sama
dijalankan otomatis oleh `tests/Feature/Database/TasklistErdConformanceTest.php` (4 test; tipe kolom hanya dicek di
MySQL), lulus di SQLite dan MySQL.

| Tabel | Kolom ERD (nama, tipe, nullable) | Index / unique ERD | Relasi ERD | Kolom di luar ERD |
|---|---|---|---|---|
| `roles` | ✅ | ✅ `code_role` unique | — | — |
| `permissions` | ✅ | ✅ `code` unique | — | — |
| `role_permissions` | ✅ | ✅ PK `(role_id, permission_id)` | ✅ 2 | — |
| `divisions` | ✅ | ✅ `code_division` unique | — | — (`name` juga unique, sudah ada sebelum TASK-19) |
| `accounts` | ✅ | ✅ `username` unique | ✅ 2 | `password` (kolom autentikasi; ERD: "tidak ditampilkan") |
| `platforms` | ✅ | ✅ `slug` unique | — | — |
| `task_categories` | ✅ | ✅ `name` unique | — | — |
| `tasks` | ✅ | ✅ `(division_id, status)`, `(status, deadline)`, `start_date`, `deleted_at` | ✅ 5 | `pic_id` (ERD: dihentikan setelah semua pembaca kompatibel) |
| `task_divisions` | ✅ | ✅ PK `(task_id, division_id)` | ✅ 3 | — |
| `task_assignments` | ✅ | ✅ `(task_id, unassigned_at)`, `(account_id, unassigned_at)` | ✅ 4 | `active_flag` (mekanisme unik PIC aktif khusus MySQL yang diminta ERD) |
| `task_evidences` | ✅ | ✅ `(task_id, created_at)` | ✅ 3 | — |
| `task_reviews` | ✅ `decision` varchar(30), `updated_at` | ✅ `(task_id, created_at)` | ✅ 2 | — (`cycle` di-drop; API menghitungnya) |
| `task_comments` | ✅ `created_at` NOT NULL | ✅ `(task_id, created_at)`, `parent_comment_id` | ✅ 3 | — |
| `task_activities` | ✅ `created_at` NOT NULL | ✅ `(task_id, created_at)` | ✅ 2 | `actor_name_snapshot` (sudah ada sebelum TASK-19, nama pelaku yang akunnya terhapus) |
| `calendar_events` | ✅ `division_id` nullable, `creator_id` NOT NULL | ✅ `start_at` | ✅ 2 | `is_shared` (penanda agenda bersama) + tabel `calendar_event_divisions` (divisi tujuan), lihat di bawah |

Perbedaan dengan ERD yang **disengaja** (5 kolom di atas) dicatat juga di `TasklistErdConformanceTest::DEVIATIONS`;
kolom lain di luar ERD membuat test gagal. Tabel di luar ERD:

- `calendar_event_divisions` (`calendar_event_id`, `division_id`; PK gabungan, FK ke `calendar_events` dan
  `divisions`): divisi tujuan agenda internal. Bersama `calendar_events.is_shared`, diminta review PR #115 agar aturan
  akses agenda yang sudah berjalan tidak berubah dan agenda lintas divisi tidak kehilangan divisi tujuannya. ERD v1.0
  belum memuat keduanya; **SA perlu menambahkannya ke ERD**. Dicek oleh
  `test_the_agenda_deviations_from_the_erd_are_only_is_shared_and_the_target_divisions`.
- `account_role_changes` (audit role, TASK-12): **perlu ditambahkan ke ERD**.
- `schedule_events` dan `schedule_event_divisions` (read-only, untuk rollback dan pemulihan; tidak dibaca aplikasi).

Nilai yang ERD minta dicocokkan dengan existing: `task_reviews.decision` tetap `approve`/`revision` (enum
`TaskReviewDecision`), status memakai kode internal ERD (`ready_review`, `on_review`). Tipe `bigint unsigned` dan
`int unsigned` dianggap sama dengan `bigint`/`int` di ERD; `boolean` = `tinyint(1)`.

### Perubahan pada revisi kedua dan ketiga

| Sebelum | Sekarang (sesuai ERD) | Migration |
|---|---|---|
| `calendar_events` + `is_shared`, `task_id`, `legacy_schedule_event_id`, `creator_id` nullable, tabel `calendar_event_divisions`, index `(start_at, end_at)` | Kolom, FK, dan index ERD (`creator_id` NOT NULL, index `start_at`, tanpa `task_id`); revisi ketiga mengembalikan `is_shared` dan `calendar_event_divisions` | `2026_11_13_083929` (tabel), `2026_11_16_090000` (hanya menyalin data), `2026_11_13_105301` (index tidak diubah lagi) |
| Agenda tanpa pembuat dicatat atas nama Admin pertama; agenda internal disempitkan ke satu `division_id`; Manajer melihat semua agenda (revisi kedua) | Agenda tanpa pembuat menghentikan migrate; semua divisi tujuan dan `is_shared` disalin; akses agenda sama dengan aplikasi sebelumnya | `2026_11_16_090000`, `CalendarEvent::scopeVisibleTo` |
| `task_reviews.decision` varchar(20), `cycle`, tanpa `updated_at` | varchar(30), `updated_at`, tanpa `cycle` | `2026_11_16_085800_align_task_reviews_with_erd` |
| `task_activities.created_at` nullable | NOT NULL | `2026_11_16_085900_require_created_at_on_task_activities` |
| `task_comments.created_at` nullable, tanpa index `parent_comment_id` eksplisit | NOT NULL, index `parent_comment_id` | `2026_11_13_083926_create_task_comments_table` |

## 3. Data dummy format lama

`LegacyTasklistSeeder` hanya mengisi kolom yang sudah ada sebelum TASK-19, jadi bisa di-seed pada schema lama:

- 3 role lama (ADM, MGR, EMP dengan nama "Employee"), 2 divisi, 6 akun.
- 6 task: satu per status lama, termasuk `ready_for_qa` dan `on_qa`; 5 punya `pic_id`, 1 Back Log tanpa PIC; versi
  1 sampai 9.
- 20 aktivitas: created, assigned, reassigned, status_changed dengan status lama, evidence_submitted,
  revision_requested, resubmitted, approved, entri dari akun yang sudah terhapus (hanya `actor_name_snapshot`), dan
  judul yang kebetulan berisi `on_qa`.
- 3 evidence (file dan link), 2 review dengan `cycle`.
- 5 agenda dengan 4 divisi tujuan, mencakup setiap bentuk: bersama dengan divisi tujuan yang tidak
  berpengaruh, internal satu divisi, internal dua divisi beberapa hari, internal tanpa divisi penyelenggara/tujuan
  (hanya Admin), dan bersama yang terkait task. Semua agenda punya pembuat.

## 4. Hasil migration pada data lama (MySQL)

Prosedur sama dengan deploy: rollback 17 migration TASK-19 → seed data lama → `migrate` → `RoleSeeder` →
`PermissionSeeder` → `tasks:validate-migration` → `tasks:recovery` → rollback → migrate ulang.

| Langkah | Hasil |
|---|---|
| Data sebelum migrate | accounts=6, roles=3, divisions=2, tasks=6, activities=20, evidences=3, reviews=2, schedule_events=5, schedule_event_divisions=4 |
| `migrate` (17 migration) | Berhasil, 4,1 detik, tanpa konflik nama tabel/kolom/foreign key |
| Data setelah migrate | Semua jumlah di atas sama (roles=5 setelah `RoleSeeder`); assignment aktif=5, status lama=0, calendar_events=5 (2 bersama), calendar_event_divisions=4; `is_shared` `tinyint(1) NOT NULL DEFAULT 0` |
| `tasks:validate-migration` | 16/16 PASS, exit 0; "Salinan agenda sama dengan aslinya": 5 sama |
| `tasks:recovery` (status) | Semua 0: "Belum ada data baru. Rollback aman: php artisan migrate:rollback --step=17 --force" |
| Tambah 1 komentar, lalu `migrate:rollback --step=17` | **Ditolak**: "Rollback TASK-19 dibatalkan karena akan menghapus data baru (Komentar tugas: 1)"; komentar dan 5 agenda tetap ada |
| Hapus komentar uji, lalu `migrate:rollback --step=17` | Berhasil; status lama kembali (2 task `ready_for_qa`/`on_qa`), `calendar_events` hilang, 5 agenda dan 4 divisi tujuan tetap di `schedule_events`, `task_reviews.cycle` kembali sama (1, 1) |
| `migrate` ulang + seed + validasi | Berhasil, "Validasi lulus" |

## 5. Validasi per poin

### 5.1 Migration & data

| Poin | Bukti |
|---|---|
| Skema sama dengan ERD | Bagian 2; `TasklistErdConformanceTest` |
| Migration jalan tanpa konflik | Bagian 4; `TasklistMigrationIntegrationTest` |
| Backfill `pic_id` → `task_assignments` tanpa kehilangan relasi | `TasklistMigrationIntegrationTest::test_every_legacy_pic_becomes_one_active_assignment_with_its_origin` |
| Migrate ulang tidak membuat assignment ganda | `TaskMultiPicTest::test_backfill_adds_missing_assignments_once_and_never_restores_a_released_pic` |
| Kompatibilitas task, akun, role, evidence, review, histori | Jumlah baris sama sebelum dan sesudah; `test_existing_accounts_keep_their_role_and_legacy_data_works_through_the_api` |
| Review, komentar, histori sesuai ERD tanpa kehilangan data | `test_reviews_comments_and_history_follow_the_erd_and_keep_their_data`: `cycle` di API sama dengan nilai lama, `updated_at` terisi, `created_at` kosong diisi waktu pembuatan task, rollback mengisi ulang `cycle` yang sama |
| Enam status dan histori QA lama terbaca | `test_statuses_and_their_history_are_renamed_and_read_with_the_new_labels` |
| Soft delete, foreign key, unique, optimistic locking | `TaskHistoryIntegrityTest`, `TasklistFoundationTest`, test `409` di `TaskAssignmentTest` |
| Rollback sebelum ada data baru | `test_rolling_back_restores_the_legacy_data_and_migrating_again_succeeds`, `TasklistRecoveryTest::test_right_after_the_deploy_there_is_no_new_data_and_rolling_back_is_allowed` |

### 5.2 Agenda (`calendar_events`)

`TasklistMigrationIntegrationTest::test_every_agenda_moves_to_calendar_events_with_its_id_access_and_target_divisions`:

| Agenda lama | `is_shared` | `division_id` (penyelenggara) | Divisi tujuan | `creator_id` | Terlihat oleh |
|---|---|---|---|---|---|
| Rapat evaluasi bulanan | 1 | Marketing | Finance (tidak berpengaruh) | Pembuat asli | Semua |
| Briefing internal Marketing | 0 | Marketing | Marketing | Pembuat asli | Admin, akun Marketing (termasuk Manajer Marketing) |
| Sinkronisasi Marketing dan Finance | 0 | Finance | Marketing, Finance | Pembuat asli | Admin, akun Marketing, akun Finance |
| Rapat pimpinan | 0 | NULL | – | Pembuat asli | Admin saja |
| Shooting video teaser (terkait task) | 1 | Marketing | – | Pembuat asli | Semua; `task_id` di API `null` |

| Poin | Hasil |
|---|---|
| Setiap agenda lama punya tepat satu salinan | 5 dari 5, id sama |
| Isi salinan | Judul, deskripsi, `is_shared`, divisi penyelenggara, **semua divisi tujuan**, pembuat, tanggal mulai/selesai, `created_at`, `updated_at` sama |
| Akses agenda | Daftar agenda di Calendar dan Timeline untuk Admin, Manajer Marketing, Manajer Finance, Staff Marketing, Staff Finance sesuai tabel di atas, sama dengan aplikasi sebelumnya |
| Agenda tanpa pembuat | `test_an_agenda_without_a_creator_stops_the_move_and_copies_nothing`: migrate berhenti dengan id dan judul agenda, tidak ada akun yang dicatat sebagai pengganti (walaupun ada akun Admin), `calendar_events` tetap kosong |
| `creator_id` wajib | Kolom NOT NULL dari `2026_11_13_083929` sampai migration terakhir. `CalendarEventCreatorTest`: agenda tanpa pembuat ditolak (langsung ke database maupun lewat model), pembuat yang tidak ada di `accounts` ditolak FK, akun pembuat agenda tidak dapat dihapus (harus dinonaktifkan) |
| Kehilangan divisi tujuan dianggap gagal | `test_the_validator_fails_when_an_agenda_loses_a_target_division_or_its_sharing`: satu divisi tujuan dihapus → FAIL "1 salinan berbeda dari agenda aslinya, 1 di antaranya kehilangan divisi tujuan"; `is_shared` diubah → FAIL; `tasks:validate-migration` exit 1 |
| Aturan akses per role | `TaskCalendarTest`, `TaskScheduleTest`: agenda bersama untuk kelima role dan akun tanpa divisi; agenda internal hanya untuk Admin dan akun divisi tujuannya (divisi yang sama ✓, divisi lain ✗, termasuk Manajer: Manajer Finance tidak melihat agenda internal Marketing/Sales); agenda lintas divisi tampil di semua divisi tujuannya; agenda internal tanpa divisi tujuan hanya Admin; divisi penyelenggara saja tidak memberi akses; akun nonaktif tidak melihat agenda; fokus divisi tidak memperluas visibilitas |

### 5.3 Hak akses per role

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

### 5.4 Pemulihan setelah fitur dipakai

`TasklistRecoveryTest` (data lama → migrate → pengguna menambah PIC kedua, membuat task multi-PIC, memindahkan status,
menambah komentar, serta menambah, mengubah, dan menghapus agenda):

| Skenario | Hasil |
|---|---|
| Rollback setelah ada data baru | Ditolak `UnsafeTasklistRollbackException`; komentar, 2 PIC aktif, dan 6 agenda tetap ada |
| `tasks:recovery to-legacy` tanpa `--apply` | Tidak ada perubahan (dry run) |
| `tasks:recovery to-legacy --apply` | `tasks.pic_id` = PIC aktif paling awal; status kembali `ready_for_qa`/`on_qa`; `schedule_events` berisi agenda baru (id sama, `is_shared` dan kedua divisi tujuan ikut), agenda yang diubah, tanpa agenda yang dihapus; agenda yang tidak diubah tetap dengan `task_id` aslinya; komentar dan assignment tetap ada |
| Aplikasi lama mengganti PIC dan status, menambah agenda bersama dan agenda internal dua divisi, menghapus agenda | Disimulasikan langsung di tabel lama |
| `tasks:recovery from-legacy --apply` | Status kembali ke nama baru; PIC yang diganti di aplikasi lama menjadi satu-satunya PIC aktif; task multi-PIC yang tidak diubah tetap punya 2 PIC; agenda baru dari aplikasi lama masuk `calendar_events` dengan `is_shared`, pembuat, dan kedua divisi tujuannya; agenda yang dihapus di aplikasi lama di-soft delete; validasi lulus; API detail benar |
| Menjalankan `from-legacy` lagi | 0 perubahan |
| `from-legacy` tanpa `to-legacy` sebelumnya | Agenda yang hanya ada di `calendar_events` tidak dihapus |

### 5.5 Kontrak API

Kontrak backend dibandingkan dengan kode FE di `dev`. Selisih, dependensi, dan urutan penerapan ada di
[tasklist-fe-integration.md](../api/tasklist-fe-integration.md). Bentuk response Calendar, Timeline, dan review tidak
berubah: `cycle` tetap dikirim, agenda tetap mengirim `division`, `target_divisions` (semua divisi tujuan), `task_id`,
`can_view_detail`, ditambah field baru `is_shared`. Aturan akses agenda sama dengan sebelumnya. Satu-satunya dampak
yang terlihat di FE: agenda tidak lagi menautkan tugas, sehingga tombol "Lihat Detail Tugas Terkait" di pop-up
agenda tidak muncul.

Temuan sebelumnya: urutan key di `changes` histori berbeda antara MySQL dan SQLite; diperbaiki dengan
`TaskActivity::orderedChanges()`.

## 6. Regression

| Suite | Hasil |
|---|---|
| Seluruh test, SQLite | 734 test: 719 lulus, 12 gagal (sudah gagal sebelum TASK-19), 3 dilewati (`PromoCodeConcurrencyTest`, butuh MySQL) |
| Task, Database, Account, Auth, Division, Web, MySQL | 486 test: 484 lulus, 2 gagal (`LoginTest`, sudah gagal sebelumnya) |
| Frontend (`npm run test:frontend`, setelah sinkron dengan `dev`) | 147/147 lulus (tidak ada perubahan FE di branch ini) |

12 kegagalan yang sudah ada sebelumnya, tidak terkait TASK-19:

- `LoginTest` (2): password di `AccountSeeder` (`manager123`) berbeda dengan yang diharapkan test.
- `PerformanceExportTest` (2) dan `PromoAnalysisExportTest` (8): ekstensi PHP `zip` belum aktif di mesin uji
  (`Class "ZipArchive" not found`).

## 7. Validasi setelah deploy

```bash
php artisan tasks:validate-migration
php artisan tasks:recovery
```

Keduanya read-only. `tasks:validate-migration` keluar dengan exit code `0` bila tidak ada FAIL, `1` bila ada.

| Pemeriksaan | Gagal bila |
|---|---|
| Migration TASK-19 sudah dijalankan | Ada migration `2026_11_13_*`/`2026_11_16_08*`/`2026_11_16_090*` yang belum jalan |
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
| Agenda lama tersalin ke `calendar_events` | Ada agenda `schedule_events` tanpa salinan dengan id dan `created_at` sama |
| Salinan agenda sama dengan aslinya | Salinan yang belum diubah berbeda judul, deskripsi, tanggal, pembuat, divisi penyelenggara, `is_shared`, atau divisi tujuannya (termasuk **kehilangan satu divisi tujuan**); atau ada agenda lama tanpa pembuat |

## 8. Prosedur pemulihan

Selalu ambil backup sebelum deploy (`mysqldump --single-transaction --routines <database>`), lalu tentukan kondisinya
dengan `php artisan tasks:recovery`.

### 8.1 Sebelum ada data baru

| Kegagalan | Tindakan |
|---|---|
| `migrate` berhenti di `2026_11_16_090000` (`UnmappableAgendaException`) | Pesan menyebut id dan judul agenda tanpa pembuat. Isi `schedule_events.creator_id` agenda itu dengan pembuat aslinya (jangan memakai akun lain), lalu `php artisan migrate` lagi. Tidak ada agenda yang tersalin sebagian. |
| `migrate` gagal di tengah karena hal lain | `php artisan migrate:status`, lalu `migrate:rollback --step=N` (N = jumlah migration TASK-19 yang sudah jalan). Bila rollback gagal, pulihkan dari backup. |
| Validasi: role/permission | `php artisan db:seed --class=RoleSeeder --force` lalu `--class=PermissionSeeder --force` (aman diulang). |
| Validasi: PIC lama belum di-backfill | `php artisan tasks:backfill-assignments`, lalu `--apply`. |
| Validasi: status QA lama tersisa | Rollback lalu jalankan ulang migration `2026_11_13_083919_rename_qa_statuses_to_review_statuses`, atau rollback penuh dan migrate ulang. |
| Validasi: agenda belum tersalin atau berbeda | Rollback migration `2026_11_16_090000_*` lalu migrate ulang; agenda yang id-nya sudah ada dilewati sehingga tidak tergandakan. |
| Aplikasi bermasalah setelah deploy | `php artisan migrate:rollback --step=17 --force` (angka pastinya ditampilkan `tasks:recovery`), lalu deploy kode versi sebelumnya. |

### 8.2 Setelah fitur dipakai

Rollback skema akan menghapus data yang tidak ada di `tasks.pic_id` maupun di backup sebelum deploy, sehingga ditolak
oleh `2026_11_16_090100_guard_tasklist_rollback`. Pulihkan dengan mempertahankan skema dan data terbaru:

1. `php artisan tasks:recovery to-legacy` (laporan), lalu `--apply`.
2. Deploy kode versi sebelumnya. Tabel baru tetap ada dan tidak dibaca aplikasi lama.
3. Saat kode TASK-19 dipasang lagi: `php artisan tasks:recovery from-legacy --apply`, lalu
   `php artisan tasks:validate-migration`.

`to-legacy --apply` menyimpan daftar agenda yang ditulisnya di cache aplikasi; jangan jalankan `cache:clear` sebelum
`from-legacy`. Bila cache terhapus, agenda yang dihapus di aplikasi lama tidak ikut dihapus (tidak ada data yang hilang)
dan bisa dihapus manual.

| Masalah lain | Tindakan |
|---|---|
| Validasi: data yatim, PIC ganda, balasan lintas task | Jangan ubah manual di production. Ekspor barisnya, laporkan, dan perbaiki lewat migration/command yang direview. |
| Penghapusan fisik ditolak FK RESTRICT | Cek baris riwayat yang mereferensikan task/akun tersebut; riwayat tidak boleh ikut terhapus. |
| Data baru memang boleh dibuang (database uji) | `TASKLIST_ALLOW_DESTRUCTIVE_ROLLBACK=true` lalu rollback. Jangan dipakai di production. |
