<?php

namespace Tests\Feature\Web;

use App\Models\Account;
use App\Models\Division;
use App\Models\Role;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PageAccessTest extends TestCase
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
            'fullname' => 'Siti Employee',
            'username' => 'employee',
            'password' => 'Employee123!',
        ]);
    }

    public function test_sidebar_preserves_menu_structure_and_has_a_mobile_dialog_lifecycle(): void
    {
        $this->actingAs($this->manager)->get('/dashboard')
            ->assertOk()
            ->assertSeeInOrder(['Analitik', 'Dashboard', 'Pekerjaan', 'Tasklist', 'Penjualan &amp; Pemasaran', 'Manajemen Promo', 'Sistem', 'Keluar', 'Akun Aktif'], false)
            ->assertSee('x-dialog="open && !desktop"', false)
            ->assertSeeInOrder(['id="sidebar-dialog"', 'x-dialog="open && !desktop"', 'x-show="open"', 'id="sidebar"', '</aside>', '<main'], false)
            ->assertSee(':inert="!desktop && !open"', false)
            ->assertSee(':aria-hidden="!desktop && !open ? \'true\' : null"', false)
            ->assertSee('@keydown.escape.stop="open = false"', false)
            ->assertSee('aria-label="Analitik"', false);
    }

    public function test_sidebar_has_a_single_dashboard_item_without_the_legacy_submenu(): void
    {
        $response = $this->actingAs($this->manager)->get('/dashboard')->assertOk();
        $sidebar = (string) str($response->getContent())->between('id="sidebar"', '</aside>');

        $analytics = (string) str($sidebar)->between('aria-label="Analitik"', '</nav>');

        $this->assertSame(1, substr_count($analytics, '<a '));
        $this->assertStringContainsString('Dashboard', $analytics);
        $this->assertMatchesRegularExpression('/href="'.preg_quote(route('dashboard'), '/').'"[^>]*aria-current="page"/s', $analytics);
        $this->assertSame(1, substr_count($sidebar, 'aria-current="page"'));

        foreach (['Analisis Performa', 'Ringkasan', 'Produk', 'sidebar-submenu', '/performance/products'] as $legacy) {
            $this->assertStringNotContainsString($legacy, $sidebar);
        }
    }

    public function test_direct_and_nested_active_sidebar_links_use_the_same_contrasted_treatment(): void
    {
        foreach (['/dashboard', '/promo'] as $url) {
            $response = $this->actingAs($this->manager)->get($url)->assertOk();
            $sidebar = str($response->getContent())->between('id="sidebar"', '</aside>');
            $this->assertMatchesRegularExpression('/class="[^"<>]*bg-primary-hover text-white[^"<>]*"[^>]*aria-current="page"/s', (string) $sidebar);
            $this->assertStringNotContainsString('before:w-[3px]', $sidebar);
        }
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/')->assertRedirect('/dashboard');
        $this->get('/dashboard')->assertRedirect(route('login'));
        $this->get('/settings')->assertRedirect(route('login'));
        $this->get('/divisions')->assertRedirect(route('login'));
    }

    public function test_guest_can_open_login_page_without_demo_hint(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee(route('api.login'), false)
            ->assertDontSee('Forgot Password?')
            ->assertSee('data-redirect="'.route('dashboard').'"', false)
            ->assertDontSee('Mode demo');
    }

    public function test_authenticated_account_is_redirected_away_from_login(): void
    {
        $this->actingAs($this->manager)
            ->get('/login')
            ->assertRedirect(route('dashboard'));
    }

    public function test_landing_page_shows_logged_in_account(): void
    {
        $this->actingAs($this->employee)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Siti Employee')
            ->assertSee('"username":"employee"', false)
            ->assertSee(route('settings.index'));
    }

    public function test_manager_can_open_organization_management_with_accounts_as_default(): void
    {
        Division::create(['code_division' => 'OPS', 'name' => 'Operasional']);

        $this->actingAs($this->manager)
            ->get('/settings')
            ->assertOk()
            ->assertViewHas('initialTab', 'accounts')
            ->assertSee('Manajemen Organisasi')
            ->assertSee('Kelola akun pengguna dan divisi organisasi.')
            ->assertSeeInOrder(['Akun', 'Divisi'])
            ->assertSeeInOrder(['Total Akun', 'Admin', 'Manager', 'Staff', 'Supervisor', 'Freelance'])
            ->assertDontSee('akun tanpa divisi')
            ->assertSeeInOrder(['Daftar Akun', 'Tambah Akun'])
            ->assertSee('@click="openCreate()"', false)
            ->assertSee('id="create-user-title"', false)
            ->assertSee('role="tablist"', false)
            ->assertSee('id="panel-accounts"', false)
            ->assertSee('id="panel-divisions"', false)
            ->assertDontSee('id="panel-create"', false)
            ->assertSee('Operasional')
            ->assertSee('>Aksi</th>', false)
            ->assertSee('openEdit(user)', false)
            ->assertSee('id="edit-user-title"', false)
            ->assertDontSee('>Status</th>', false);
    }

    public function test_sidebar_has_one_organization_management_link_for_manager(): void
    {
        $this->actingAs($this->manager)
            ->get('/dashboard')
            ->assertOk()
            ->assertSeeInOrder(['Manajemen Organisasi', 'Keluar'])
            ->assertSee('href="'.route('settings.index').'"', false)
            ->assertDontSee('Account Management')
            ->assertDontSee('Division Management')
            ->assertDontSee("soon('Tasklist')", false);
    }

    public function test_sidebar_marks_settings_active_on_settings_page(): void
    {
        $this->actingAs($this->manager)
            ->get('/settings')
            ->assertOk()
            ->assertSee('aria-current="page"', false);
    }

    public function test_sidebar_links_settings_for_employee(): void
    {
        $this->actingAs($this->employee)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('href="'.route('settings.index').'"', false)
            ->assertDontSee("locked('Manajemen Organisasi')", false);
    }

    public function test_employee_can_open_settings_page_as_read_only(): void
    {
        $this->actingAs($this->employee)
            ->get('/settings')
            ->assertOk()
            ->assertSee('Daftar Akun')
            ->assertSee('Manajemen Organisasi')
            ->assertSee('Cari akun anggota tim dan saring berdasarkan divisi.')
            ->assertSee('accountSettings(', false)
            ->assertDontSee('Tambah Akun')
            ->assertDontSee('openCreate()', false)
            ->assertDontSee('id="create-user-title"', false)
            ->assertDontSee('id="panel-create"', false)
            ->assertSee('role="tablist"', false)
            ->assertDontSee('>Aksi</th>', false)
            ->assertDontSee('openEdit(user)', false)
            ->assertDontSee('openDelete(user)', false)
            ->assertDontSee('id="edit-user-title"', false)
            ->assertDontSee('id="delete-user-title"', false)
            ->assertDontSee('>Status</th>', false);
    }

    public function test_divisions_query_opens_divisions_tab_with_management_controls(): void
    {
        $this->actingAs($this->manager)
            ->get('/settings?tab=divisions')
            ->assertOk()
            ->assertViewHas('initialTab', 'divisions')
            ->assertSeeInOrder(['Total Divisi', 'Daftar Divisi'])
            ->assertSeeInOrder(['Daftar Divisi', 'Tambah Divisi'])
            ->assertSee('id="create-division-title"', false)
            ->assertSee('Hapus Divisi?');
    }

    public function test_employee_can_open_divisions_tab_read_only(): void
    {
        $this->actingAs($this->employee)
            ->get('/settings?tab=divisions')
            ->assertOk()
            ->assertViewHas('initialTab', 'divisions')
            ->assertSee('Daftar Divisi')
            ->assertDontSee('Tambah Divisi')
            ->assertDontSee('Hapus Divisi?');
    }

    public function test_invalid_tab_falls_back_to_accounts(): void
    {
        $this->actingAs($this->manager)
            ->get('/settings?tab=unknown')
            ->assertOk()
            ->assertViewHas('initialTab', 'accounts');
    }

    public function test_legacy_divisions_route_redirects_to_divisions_tab(): void
    {
        $this->actingAs($this->manager)
            ->get('/divisions')
            ->assertRedirect('/settings?tab=divisions');
    }
}
