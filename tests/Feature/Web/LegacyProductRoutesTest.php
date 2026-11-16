<?php

namespace Tests\Feature\Web;

use App\Models\Account;
use App\Models\Role;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class LegacyProductRoutesTest extends TestCase
{
    use RefreshDatabase;

    private const LEGACY_API_ENDPOINTS = [
        '/api/products/filters',
        '/api/products/top-packages/revenue',
        '/api/products/top-packages/volume',
        '/api/products/performance',
    ];

    private const DASHBOARD_API_ENDPOINTS = [
        '/api/performance/summary?date_from=2026-09-01&date_to=2026-09-30',
        '/api/performance/trend?date_from=2026-09-01&date_to=2026-09-30&metric=revenue&period=daily',
        '/api/performance/payment-methods?date_from=2026-09-01&date_to=2026-09-30',
        '/api/performance/platform-contribution?date_from=2026-09-01&date_to=2026-09-30',
        '/api/performance/purchase-times?date_from=2026-09-01&date_to=2026-09-30',
    ];

    /** @var list<Account> */
    private array $accounts;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->seed(DatabaseSeeder::class);

        $this->accounts = [
            Account::where('username', 'manager')->first(),
            Account::create([
                'role_id' => Role::where('code_role', Role::EMPLOYEE)->value('id'),
                'fullname' => 'Siti Employee',
                'username' => 'employee',
                'password' => 'Employee123!',
            ]),
        ];
    }

    public function test_legacy_product_routes_are_not_registered(): void
    {
        $this->assertFalse(Route::has('performance.products'));

        $legacyApiRoutes = collect(Route::getRoutes()->getRoutesByName())
            ->keys()
            ->filter(fn (string $name) => str_starts_with($name, 'api.products.'));

        $this->assertEmpty($legacyApiRoutes);
    }

    public function test_authorized_session_gets_404_without_redirect_on_legacy_page(): void
    {
        foreach ($this->accounts as $account) {
            $this->actingAs($account)
                ->get('/performance/products')
                ->assertNotFound()
                ->assertHeaderMissing('Location');
        }
    }

    public function test_authorized_session_gets_404_without_redirect_on_legacy_api_endpoints(): void
    {
        foreach ($this->accounts as $account) {
            foreach (self::LEGACY_API_ENDPOINTS as $url) {
                $this->actingAs($account)
                    ->getJson($url)
                    ->assertNotFound()
                    ->assertHeaderMissing('Location');
            }
        }
    }

    public function test_authorized_accounts_can_still_open_dashboard_and_its_datasets(): void
    {
        foreach ($this->accounts as $account) {
            $this->actingAs($account)->get('/dashboard')->assertOk();

            foreach (self::DASHBOARD_API_ENDPOINTS as $url) {
                $this->actingAs($account)->getJson($url)->assertOk();
            }
        }
    }

    public function test_guest_still_follows_dashboard_authentication_rules(): void
    {
        $this->get('/dashboard')->assertRedirect(route('login'));

        foreach (self::DASHBOARD_API_ENDPOINTS as $url) {
            $this->getJson($url)->assertUnauthorized();
        }
    }
}
