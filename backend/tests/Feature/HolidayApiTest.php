<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class HolidayApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_holiday_names_are_classified_and_special_working_days_are_excluded(): void
    {
        Http::fake([
            'date.nager.at/api/v3/PublicHolidays/2024/PH' => Http::response([
                [
                    'date' => '2024-08-21',
                    'localName' => 'Araw ng Kamatayan ni Senador Benigno Simeon "Ninoy" Aquino Jr.',
                    'name' => 'Ninoy Aquino Day',
                    'types' => ['Public'],
                ],
                [
                    'date' => '2024-08-26',
                    'localName' => 'Araw ng mga Bayani',
                    'name' => 'National Heroes Day',
                    'types' => ['Public'],
                ],
                [
                    'date' => '2024-08-31',
                    'localName' => 'Special working day',
                    'name' => 'Special working day',
                    'types' => ['Special Working Day'],
                ],
            ]),
        ]);

        $this->getJson('/api/holidays/2024')
            ->assertOk()
            ->assertJsonPath('country', 'PH')
            ->assertJsonPath('year', 2024)
            ->assertJsonPath('source', 'Nager.Date Public Holidays API')
            ->assertJsonPath('holidays.0.name', 'Ninoy Aquino Day')
            ->assertJsonPath('holidays.1.name', 'National Heroes Day')
            ->assertJsonPath('holidays.0.type', 'Special Non-Working Day')
            ->assertJsonPath('holidays.1.type', 'Regular Holiday')
            ->assertJsonCount(2, 'holidays');

        Http::assertSent(fn (ClientRequest $request) => str_contains($request->url(), '/PublicHolidays/2024/PH'));
    }

    public function test_islamic_holidays_match_eid_spelling_variants_and_approved_exceptions(): void
    {
        Http::fake([
            'date.nager.at/api/v3/PublicHolidays/2025/PH' => Http::response([
                ['date' => '2025-04-01', 'localName' => 'Eid’l Fitr', 'name' => 'Feast of Ramadhan', 'types' => ['Public']],
                ['date' => '2025-06-06', 'localName' => "Eidul Adha", 'name' => 'Feast of Sacrifice', 'types' => ['Public']],
                ['date' => '2025-10-31', 'localName' => "All Saints' Day Eve", 'name' => 'All Saints’ Day Eve', 'types' => ['Public']],
                ['date' => '2025-05-12', 'localName' => 'Halalan 2025', 'name' => 'Midterm Elections', 'types' => ['Public']],
            ]),
        ]);

        $this->getJson('/api/holidays/2025')
            ->assertOk()
            ->assertJsonPath('holidays.0.type', 'Islamic Holiday')
            ->assertJsonPath('holidays.1.type', 'Islamic Holiday')
            ->assertJsonPath('holidays.2.type', 'Special Non-Working Day')
            ->assertJsonPath('holidays.3.type', 'Special Non-Working Day');
    }

    public function test_years_outside_the_supported_range_are_rejected(): void
    {
        $this->getJson('/api/holidays/2019')->assertNotFound();
        $this->getJson('/api/holidays/2028')->assertNotFound();
    }

    public function test_unmapped_names_fail_without_defaulting_to_regular_holiday(): void
    {
        Log::spy();
        Http::fake([
            'date.nager.at/api/v3/PublicHolidays/2026/PH' => Http::response([
                ['date' => '2026-08-01', 'localName' => 'Unknown Day', 'name' => 'Unknown Day', 'types' => ['Public']],
            ]),
        ]);

        $this->getJson('/api/holidays/2026')
            ->assertStatus(502)
            ->assertJsonPath('message', 'Could not classify holiday "Unknown Day" for 2026. The holiday data was not displayed.');

        Log::shouldHaveReceived('error')
            ->once()
            ->with('Philippine holiday name has no configured category.', \Mockery::on(
                fn (array $context): bool => $context['year'] === 2026 && $context['local_name'] === 'Unknown Day',
            ));
    }

    public function test_upstream_failures_are_reported_without_fallback_holiday_data(): void
    {
        Http::fake([
            'date.nager.at/api/v3/PublicHolidays/2026/PH' => Http::response([], 503),
        ]);

        $this->getJson('/api/holidays/2026')
            ->assertStatus(502)
            ->assertJsonPath('message', 'Could not fetch holidays for 2026. Please try again.');
    }
}
