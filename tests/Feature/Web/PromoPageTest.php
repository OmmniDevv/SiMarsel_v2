<?php

namespace Tests\Feature\Web;

use App\Models\Account;
use App\Models\Role;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromoPageTest extends TestCase
{
    use RefreshDatabase;

    private Account $manager;

    private Account $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->seed(DatabaseSeeder::class);

        $this->manager = Account::where('username', 'manager')->first();
        $this->employee = Account::create([
            'role_id' => Role::where('code_role', Role::EMPLOYEE)->value('id'),
            'fullname' => 'Sales Marketing',
            'username' => 'sales',
            'password' => 'Employee123!',
        ]);
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/promo')->assertRedirect(route('login'));
    }

    public function test_every_role_can_open_promo_page_with_three_tabs(): void
    {
        foreach ([$this->manager, $this->employee] as $account) {
            $this->actingAs($account)
                ->get(route('promo.index'))
                ->assertOk()
                ->assertSee('Manajemen Promo')
                ->assertSee('x-data="promoManagement"', false)
                ->assertSeeInOrder(['role="tablist"', 'Analisis', 'Kelola Kode', 'Data Master'], false)
                ->assertSeeInOrder(['id="tab-promo-analysis"', 'id="tab-promo-promo-codes"', 'id="tab-promo-master-data"'], false)
                ->assertSee('id="panel-promo-analysis"', false)
                ->assertSee('id="panel-promo-promo-codes"', false)
                ->assertSee('id="panel-promo-master-data"', false);
        }
    }

    public function test_analysis_code_management_and_master_data_live_in_separate_tabs(): void
    {
        $this->actingAs($this->employee)
            ->get(route('promo.index'))
            ->assertSeeInOrder([
                'id="panel-promo-analysis"', 'x-data="promoAnalysis"', 'x-data="promoAnalysisCode"',
                'id="panel-promo-promo-codes"', 'x-data="promoCodeManagement"',
                'id="panel-promo-master-data"', "promoMasterTable('funnel', 'Funnel')",
            ], false);
    }

    public function test_analysis_tab_orders_filter_summary_trend_and_breakdown(): void
    {
        $this->actingAs($this->employee)
            ->get(route('promo.index'))
            ->assertSeeInOrder(['Filter Analisis', 'Ringkasan Performa', 'Tren Penggunaan Promo', 'Rincian Performa'])
            ->assertSeeInOrder([
                'aria-label="Kategori Rincian Performa"',
                'Top 5 Kode Promo', 'Per Funnel', 'Per Source', 'Perdana vs Perpanjangan', 'Rekap per Kode Promo',
                'id="panel-promo-breakdown-top-codes"', 'x-data="promoAnalysisTopCodes"',
                'id="panel-promo-breakdown-funnel"', 'x-data="promoAnalysisFunnel"',
                'id="panel-promo-breakdown-source"', 'x-data="promoAnalysisSource"',
                'id="panel-promo-breakdown-product-type"', 'x-data="promoAnalysisProductType"',
                'id="panel-promo-breakdown-codes"', 'x-data="promoAnalysisCode"',
            ], false);
    }

    public function test_promo_analysis_filter_shows_period_and_platform_first_and_keeps_every_filter(): void
    {
        $this->actingAs($this->employee)
            ->get(route('promo.index'))
            ->assertSee('x-data="promoAnalysis"', false)
            ->assertSee('@promo-analysis-errors="showServerErrors($event.detail)"', false)
            ->assertSee('@submit.prevent="apply()"', false)
            ->assertSeeInOrder(['Periode', 'Platform', 'Filter lanjutan', 'Metode Pembayaran', 'Kode Promo', 'Terapkan filter', 'Atur ulang'])
            ->assertSee('aria-controls="promo-analysis-controls"', false)
            ->assertSee('x-show="filtersOpen"', false)
            ->assertSee('aria-controls="promo-analysis-advanced"', false)
            ->assertSee('id="promo-analysis-month"', false)
            ->assertSee('id="promo-analysis-year"', false)
            ->assertSee('id="promo-analysis-start"', false)
            ->assertSee('id="promo-analysis-end"', false)
            ->assertSee('@click="resetFilter()"', false)
            ->assertSee('Data yang ditampilkan')
            ->assertSee('x-for="item in activeFilters"', false)
            ->assertSee('tanggal bayar Markaz');
    }

    public function test_promo_analysis_kpi_cards_follow_the_shared_filter(): void
    {
        $this->actingAs($this->employee)
            ->get(route('promo.index'))
            ->assertSee('x-data="promoAnalysisSummary', false)
            ->assertSee('@promo-analysis-filter.window="load($event.detail)"', false)
            ->assertSeeInOrder([
                'Revenue Promo',
                'Transaksi Promo',
                'Revenue tanpa Promo',
                'Transaksi tanpa Promo',
                'Persentase Transaksi Promo',
                'Kode Promo Terpakai',
            ])
            ->assertDontSee('Active Codes')
            ->assertDontSee('Kode Aktif');
    }

    public function test_promo_trend_chart_and_top_codes_follow_the_shared_filter_after_the_kpi_cards(): void
    {
        $response = $this->actingAs($this->employee)
            ->get(route('promo.index'))
            ->assertSeeInOrder([
                'x-data="promoAnalysis"',
                'x-data="promoAnalysisSummary',
                'x-data="promoAnalysisTrend"',
                'x-data="promoAnalysisTopCodes"',
                'x-data="promoAnalysisFunnel"',
                'Kode promo terdaftar',
            ], false)
            ->assertSeeInOrder(['Tren Penggunaan Promo', 'aria-label="Periode Grafik"', 'Harian', 'Mingguan', 'Bulanan', 'x-ref="canvas"'], false)
            ->assertSee('@change="setPeriod(\'weekly\')"', false)
            ->assertSee('Lihat data tren')
            ->assertSee('Periode berjalan:')
            ->assertSee('Pembanding:')
            ->assertSeeInOrder(['5 Kode Promo Teratas', 'Peringkat', 'Kode Promo', 'Jumlah Penggunaan', 'Revenue'])
            ->assertSee('Jumlah penggunaan dapat melebihi jumlah transaksi unik karena satu transaksi dapat memakai dua kode promo.')
            ->assertSee('Memuat 5 kode promo teratas');

        $trend = str($response->getContent())->between('id="promo-trend-title"', 'id="promo-top-codes-title"');
        $this->assertStringNotContainsString('Jumlah Penggunaan', $trend);
    }

    public function test_promo_funnel_table_follows_the_shared_filter_inside_the_analysis_section(): void
    {
        $this->actingAs($this->employee)
            ->get(route('promo.index'))
            ->assertSeeInOrder([
                'x-data="promoAnalysis"',
                'x-data="promoAnalysisSummary',
                'x-data="promoAnalysisFunnel"',
                'Kode promo terdaftar',
            ], false)
            ->assertSee('@promo-analysis-filter.window="load($event.detail)"', false)
            ->assertSeeInOrder(['Analisis Promo per Funnel', 'Funnel', 'Jumlah Transaksi', 'Jumlah Revenue', 'Kontribusi Funnel (%)'])
            ->assertSee('Memuat rekap funnel');
    }

    public function test_promo_source_table_follows_the_shared_filter_after_the_funnel_table(): void
    {
        $this->actingAs($this->employee)
            ->get(route('promo.index'))
            ->assertSeeInOrder([
                'x-data="promoAnalysis"',
                'x-data="promoAnalysisFunnel"',
                'x-data="promoAnalysisSource"',
                'Kode promo terdaftar',
            ], false)
            ->assertSeeInOrder(['Analisis Promo per Source', 'Source', 'Jumlah Penggunaan', 'Jumlah Revenue', 'Kontribusi Source (%)'])
            ->assertSee('Jumlah penggunaan dapat melebihi jumlah transaksi unik karena satu transaksi dapat memakai dua kode promo.')
            ->assertSee('Memuat rekap source');
    }

    public function test_promo_product_type_table_follows_the_shared_filter_after_the_source_table(): void
    {
        $this->actingAs($this->employee)
            ->get(route('promo.index'))
            ->assertSeeInOrder([
                'x-data="promoAnalysis"',
                'x-data="promoAnalysisSource"',
                'x-data="promoAnalysisProductType"',
                'x-data="promoAnalysisCode"',
                'Kode promo terdaftar',
            ], false)
            ->assertSeeInOrder(['Analisis Promo Perdana vs Perpanjangan', 'Tipe Produk', 'Jumlah Transaksi', 'Revenue'])
            ->assertDontSee('Persentase Transaksi (%)')
            ->assertSee('Dihitung per transaksi unik yang memakai promo, bukan per penggunaan kode; transaksi dengan dua kode promo dihitung satu kali.')
            ->assertSee('Memuat rekap tipe produk');
    }

    public function test_active_filter_row_offers_export_beside_filter_toggle_and_preserves_detail_copy(): void
    {
        $this->actingAs($this->employee)
            ->get(route('promo.index'))
            ->assertSeeInOrder([
                'x-data="promoAnalysis"',
                'id="promo-analysis-title"',
                'item in activeFilters',
                'Ubah filter',
                'x-data="promoAnalysisActions"',
                'Ekspor Excel',
                'id="promo-analysis-search"',
                'id="promo-summary-title"',
            ], false)
            ->assertDontSee('promo-analysis-copy-target', false)
            ->assertDontSee('Komponen yang Disalin')
            ->assertDontSee('<h2 class="text-lg font-semibold text-ink">Analisis Promo</h2>', false)
            ->assertSee('@promo-analysis-filter.window="clearStatus()"', false)
            ->assertSeeInOrder(['x-data="promoAnalysisCodeUsage"', 'x-ref="codeUsageClose"', '@click="copy()"', 'x-text="copyError"'], false);
    }

    public function test_export_keeps_chart_and_table_settings_without_obsolete_copy_listeners(): void
    {
        $response = $this->actingAs($this->employee)->get(route('promo.index'));

        $response->assertDontSee('@promo-analysis-copy', false);
        $this->assertSame(2, substr_count($response->getContent(), '@promo-analysis-export-params.window="provideExportParams($event.detail)"'));
        $response->assertSee('x-data="promoAnalysisSummary', false);
    }

    public function test_summary_is_followed_directly_by_the_trend_and_top_codes_remains_a_detail_tab(): void
    {
        $response = $this->actingAs($this->employee)->get(route('promo.index'));
        $between = str($response->getContent())->between('id="promo-summary-title"', 'x-data="promoAnalysisTrend"');

        $this->assertStringNotContainsString('promoAnalysisActions', $between);
        $this->assertStringNotContainsString('copyTarget', $between);
        $response->assertSee('id="tab-promo-breakdown-top-codes"', false)
            ->assertSee('aria-controls="panel-promo-breakdown-top-codes"', false)
            ->assertSee('Top 5 Kode Promo')
            ->assertSee('x-data="promoAnalysisTopCodes"', false);
    }

    public function test_promo_code_summary_follows_the_shared_filter_and_opens_a_read_only_usage_detail(): void
    {
        $response = $this->actingAs($this->employee)
            ->get(route('promo.index'))
            ->assertSeeInOrder([
                'x-data="promoAnalysis"',
                'x-data="promoAnalysisSource"',
                'x-data="promoAnalysisCode"',
                'x-data="promoAnalysisCodeUsage"',
                'Kode promo terdaftar',
            ], false)
            ->assertSee('@promo-code-usage-open.window', false)
            ->assertSeeInOrder(['Rekap per Kode Promo', 'Kode Promo', 'Platform', 'Kategori/Funnel', 'Source', 'Jumlah Penggunaan', 'Jumlah Revenue', 'Kontribusi (%)'])
            ->assertSee('Memuat rekap kode promo')
            ->assertSee('Detail Penggunaan Kode Promo')
            ->assertSeeInOrder(['Tanggal', 'Platform/Aplikasi', 'Jumlah Penggunaan', 'Revenue', 'Total']);

        $summary = str($response->getContent())->between('id="promo-code-summary-title"', 'Kode promo terdaftar');
        $this->assertStringNotContainsString('Tipe', $summary);
        $this->assertStringNotContainsString('Import', $summary);
    }

    public function test_promo_code_list_shows_mapping_columns_with_search_and_pagination(): void
    {
        $this->actingAs($this->employee)
            ->get(route('promo.index'))
            ->assertSee('x-data="promoCodeManagement"', false)
            ->assertSeeInOrder(['Kode Promo', 'Platform', 'Funnel', 'Source', 'Penggunaan', 'Aksi'])
            ->assertSee('Cari kode, platform, funnel, atau source')
            ->assertSee('Semua Platform')
            ->assertSee('aria-label="Navigasi halaman kode promo"', false)
            ->assertSeeInOrder(['Sebelumnya', 'Berikutnya']);
    }

    public function test_single_entry_form_has_required_code_and_platform_and_optional_funnel_source(): void
    {
        $this->actingAs($this->employee)
            ->get(route('promo.index'))
            ->assertSee('Tambah Kode Promo')
            ->assertSee('id="promo-code-form-title"', false)
            ->assertSeeInOrder(['for="promo-code-code"', 'for="promo-code-platform"', 'for="promo-code-funnel"', 'for="promo-code-source"'], false)
            ->assertSee('Opsional, boleh dikosongkan jika belum diketahui.')
            ->assertSee('Kode yang sama boleh dipakai di platform berbeda.');
    }

    public function test_promo_code_form_has_no_type_status_or_effective_date_field(): void
    {
        $response = $this->actingAs($this->employee)->get(route('promo.index'));

        foreach (['Code Type', 'Tipe Kode', 'Inactive', 'Nonaktif', 'is_active', 'Effective Date', 'Tanggal Efektif'] as $text) {
            $response->assertDontSee($text, false);
        }

        // The analysis Date Range filter has date inputs; the promo code form itself must not.
        $this->view('pages.promo.components.promo-code-form-modal')->assertDontSee('type="date"', false);
    }

    public function test_funnel_or_source_change_asks_for_confirmation_before_saving(): void
    {
        $this->actingAs($this->employee)
            ->get(route('promo.index'))
            ->assertSee('Konfirmasi Perubahan Funnel/Source')
            ->assertSee('Perubahan Funnel/Sumber berlaku untuk penggunaan setelah perubahan disimpan.')
            ->assertSee('Transaksi historis yang sudah terklasifikasi tidak akan berubah. Lanjutkan?')
            ->assertSeeInOrder(['@click="cancelConfirm()"', 'Batal', '@click="save()"', 'Lanjutkan'], false);
    }

    public function test_promo_code_delete_explains_used_codes_cannot_be_deleted(): void
    {
        $this->actingAs($this->employee)
            ->get(route('promo.index'))
            ->assertSee('Hapus Kode Promo?')
            ->assertSee('sehingga tidak dapat dihapus.');
    }

    public function test_bulk_entry_has_spreadsheet_columns_without_type_or_status(): void
    {
        $response = $this->actingAs($this->employee)
            ->get(route('promo.index'))
            ->assertSee('@click="$dispatch(\'promo-bulk-open\')"', false)
            ->assertSee('x-data="promoCodeBulkEntry"', false)
            ->assertSee('id="promo-bulk-title"', false)
            ->assertSeeInOrder(['Kode Promo', 'Platform', 'Funnel', 'Source', 'Status'])
            ->assertSeeInOrder(['Tambah Baris', 'Tambah 5 Baris', 'Tutup', 'Simpan Semua']);

        foreach (['Code Type', 'Tipe Kode', 'Inactive', 'Nonaktif', 'is_active'] as $text) {
            $response->assertDontSee($text, false);
        }
    }

    public function test_bulk_entry_shows_succeeded_and_failed_counts(): void
    {
        $this->actingAs($this->employee)
            ->get(route('promo.index'))
            ->assertSeeInOrder(['Diproses', 'Berhasil', 'Gagal'])
            ->assertSee('Baris yang sudah tersimpan tidak dikirim ulang.')
            ->assertSee('@promo-codes-changed.window="reload()"', false);
    }

    public function test_promo_dialogs_and_bulk_fields_expose_shared_keyboard_and_error_support(): void
    {
        $this->actingAs($this->employee)->get(route('promo.index'))
            ->assertSee('x-dialog="isOpen"', false)
            ->assertSee('x-dialog="confirming"', false)
            ->assertSee('x-dialog="modal === \'form\'"', false)
            ->assertSee('data-dialog-initial', false)
            ->assertSee('aria-required="true"', false)
            ->assertSee(":aria-describedby=\"row.errors.code ? 'promo-bulk-'", false)
            ->assertSee('Isi kode dan pilih platform pada setiap baris.')
            ->assertSee('Baris kosong tidak dikirim.')
            ->assertSee('@keydown.ctrl.enter.prevent="submit()"', false);
    }

    public function test_code_management_separates_transaction_review_from_registered_table_actions(): void
    {
        $this->actingAs($this->employee)
            ->get(route('promo.index'))
            ->assertSeeInOrder([
                'x-data="promoCodeManagement"',
                'x-data="promoUnregisteredCodes"',
                'id="unregistered-review"',
                'id="promo-list-title"',
                'Kode promo terdaftar',
                'Tambah kode promo',
                'Input massal',
                'for="promo-code-search"',
                'for="promo-code-platform-filter"',
                '<table',
                'aria-label="Navigasi halaman kode promo"',
            ], false);
    }

    public function test_promo_code_delete_sits_in_the_more_actions_menu_next_to_edit(): void
    {
        $this->actingAs($this->employee)
            ->get(route('promo.index'))
            ->assertSeeInOrder([
                '@click="openEdit(item)"',
                'x-data="promoRowMenu"',
                'aria-haspopup="menu"',
                'role="menu"',
                'role="menuitem"',
                '@click="close(true); openDelete(item)"',
            ], false);
    }

    public function test_unregistered_codes_open_a_compact_review_list_with_platform_identity(): void
    {
        $response = $this->actingAs($this->employee)
            ->get(route('promo.index'))
            ->assertSee('x-data="promoUnregisteredCodes"', false)
            ->assertSee('x-show="visible"', false)
            ->assertSeeInOrder(['x-show="totalError && !reviewOpen"', 'x-text="totalError"', '@click="refresh()">Coba Lagi'], false)
            ->assertSeeInOrder([
                'Kode transaksi belum terdaftar',
                'Ada kode pada transaksi yang belum terdaftar pada sistem. Silakan daftarkan kode tersebut agar dapat dianalisis.',
                'Tinjau kode',
                'aria-label="Daftar kode transaksi belum terdaftar"',
            ], false)
            ->assertSeeInOrder(['Cari kode belum terdaftar', 'Kode transaksi untuk ditinjau', 'Variasi kode asli', 'Kode asli', 'Didaftarkan sebagai:', 'Daftarkan', 'Tentang pemetaan transaksi'])
            ->assertSee('aria-label="Navigasi halaman kode belum terdaftar"', false)
            ->assertSee('Transaksi dengan kode ini tetap tersimpan dan tetap dihitung dalam analisis.')
            ->assertDontSee('Kode Promo Belum Terdaftar');

        $review = str($response->getContent())->between('id="unregistered-review"', 'id="promo-list-title"');
        $this->assertStringContainsString('<ul', $review);
        $this->assertStringNotContainsString('<table', $review);
        $this->assertStringContainsString('divide-y divide-line', $review);
        $this->assertStringContainsString("item.platform.id + ':' + item.code", $review);
        $this->assertStringContainsString('x-text="item.platform.name"', $review);
        $this->assertStringContainsString('x-text="item.register.code"', $review);
        $this->assertStringContainsString('@click="register(item)"', $review);
        $this->assertStringContainsString('item.transactions_count', $review);
        $this->assertStringContainsString('item.first_used_on', $review);
        $this->assertStringContainsString('x-show="totalError && !loadError"', $review);
        $this->assertStringContainsString('@click="fetchPendingTotal()"', $review);
        $response->assertSee('x-show="reviewOpen"', false)
            ->assertSee('aria-controls="unregistered-review"', false)
            ->assertSee(':aria-expanded="reviewOpen.toString()"', false)
            ->assertSee('pendingTotal + \' perlu ditinjau\'', false)
            ->assertSee('hasil tinjauan')
            ->assertSee('x-dialog="reviewOpen"', false)
            ->assertSee('@keydown.escape.stop="closeReview()"', false)
            ->assertSee('aria-labelledby="unregistered-review-title"', false)
            ->assertSee('h-dvh w-full min-w-0 flex-col bg-surface shadow-xl lg:max-w-3xl', false)
            ->assertSee('id="unregistered-search" data-dialog-initial', false)
            ->assertSee('x-show="visible || totalError || reviewOpen"', false)
            ->assertSee('Semua kode transaksi sudah terdaftar.');
    }

    public function test_registering_from_alert_opens_prefilled_single_entry(): void
    {
        $this->actingAs($this->employee)
            ->get(route('promo.index'))
            ->assertSee('@promo-code-register.window="openRegister($event.detail)"', false)
            ->assertSee('@promo-codes-changed.window="refresh()"', false)
            ->assertSee('Daftarkan Kode Promo')
            ->assertSeeInOrder(['for="promo-code-source"', 'id="promo-registration-impact"', 'penggunaan historis yang sebelumnya belum terpetakan akan dihubungkan', 'Simpan Kode Promo'], false)
            ->assertSee('Mendaftarkan kode dari alert juga dapat melengkapi')
            ->assertSee('penggunaan historis yang sebelumnya belum terpetakan akan dihubungkan')
            ->assertSee('Hanya penggunaan yang belum terhubung ke kode promo yang diproses; penggunaan yang sudah terhubung tidak diproses ulang meskipun funnel/source-nya kosong. Klasifikasi Funnel/Source yang sudah ada tidak akan ditimpa.')
            ->assertDontSee('Deactivate');
    }

    public function test_master_data_tab_manages_funnel_and_source_separately(): void
    {
        $this->actingAs($this->employee)
            ->get(route('promo.index'))
            ->assertSeeInOrder([
                'aria-label="Data Master"',
                'id="panel-promo-master-funnel"', "promoMasterTable('funnel', 'Funnel')",
                'id="panel-promo-master-source"', "promoMasterTable('source', 'Source')",
                'id="panel-promo-master-platform"', 'x-data="promoPlatformTable"',
            ], false)
            ->assertSee('Mengganti nama funnel atau source tidak mengubah riwayat penggunaan kode promo yang sudah tercatat.')
            ->assertSee("promoMasterTable('funnel', 'Funnel')", false)
            ->assertSee("promoMasterTable('source', 'Source')", false)
            ->assertSeeInOrder(['Daftar Funnel', 'Tambah Funnel', 'Daftar Source', 'Tambah Source'])
            ->assertSee('id="funnel-form-title"', false)
            ->assertSee('id="source-form-title"', false)
            ->assertSeeInOrder(['Hapus Funnel?', 'Hapus Source?']);
    }

    public function test_master_lists_put_navigation_first_and_identify_result_scope(): void
    {
        $response = $this->actingAs($this->employee)->get(route('promo.index'));
        $response->assertSeeInOrder([
            'id="panel-promo-master-data"', 'aria-label="Data Master"',
            'id="tab-promo-master-funnel"', 'id="tab-promo-master-source"', 'id="tab-promo-master-platform"',
            'id="panel-promo-master-funnel"',
        ], false);
        $response->assertDontSee('Tentang kelompok analisis');
        foreach (['funnel', 'source'] as $type) {
            $list = str($response->getContent())->between('id="'.$type.'-list-title"', 'id="'.$type.'-form-title"');
            $this->assertStringContainsString('x-show="!loading && !loadError"', $list);
            $this->assertStringContainsString('x-text="filteredItems.length"', $list);
            $this->assertStringContainsString('x-text="items.length"', $list);
            $this->assertStringContainsString('Kode promo terkait', $list);
            $this->assertStringContainsString('x-text="item.promo_codes_count', $list);
            $this->assertStringNotContainsString('Belum Dipakai', $list);
            $this->assertStringContainsString('@click="openEdit(item)"', $list);
            $this->assertStringContainsString('@click="close(true); openDelete(item)"', $list);
        }
        $platform = str($response->getContent())->after('x-data="promoPlatformTable"');
        $this->assertStringContainsString('x-text="platforms.length"', $platform);
        $this->assertStringNotContainsString('@click="openCreate()"', $platform);
        $this->assertStringNotContainsString('@click="openEdit', $platform);
        $this->assertStringNotContainsString('@click="openDelete', $platform);
    }

    public function test_platform_list_is_read_only(): void
    {
        $this->actingAs($this->employee)
            ->get(route('promo.index'))
            ->assertSee('x-data="promoPlatformTable"', false)
            ->assertSeeInOrder(['Daftar Platform', 'Baca Saja'])
            ->assertSee('Data dikelola secara terpusat dari sistem utama.')
            ->assertDontSee('Create Platform')
            ->assertDontSee('Edit Platform')
            ->assertDontSee('Delete Platform');
    }

    public function test_sidebar_links_promo_page_for_every_role(): void
    {
        foreach ([$this->manager, $this->employee] as $account) {
            $this->actingAs($account)
                ->get(route('promo.index'))
                ->assertSeeInOrder(['Penjualan &amp; Pemasaran', 'Manajemen Promo'], false)
                ->assertSee('href="'.route('promo.index').'"', false)
                ->assertSee('aria-current="page"', false);
        }
    }
}
