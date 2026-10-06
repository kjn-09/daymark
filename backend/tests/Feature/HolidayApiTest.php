<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HolidayApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_fetches_sorts_and_classifies_external_public_holidays(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://date.nager.at/api/v3/PublicHolidays/2026/PH' => Http::response([
                ['date' => '2026-12-25', 'name' => 'Christmas Day', 'localName' => 'Pasko', 'types' => ['Public']],
                ['date' => '2026-02-17', 'name' => 'Chinese New Year', 'localName' => 'Bagong Taon ng Tsino', 'types' => ['Public']],
                ['date' => '2026-03-20', 'name' => 'Eid al-Fitr', 'localName' => "Eid'l Fitr", 'types' => ['Public']],
                ['date' => '2026-11-02', 'name' => 'All Souls Day', 'types' => ['Observance']],
            ]),
        ]);

        $this->getJson('/api/holidays/2026')
            ->assertOk()
            ->assertExactJson([
                'country' => 'PH',
                'year' => 2026,
                'source' => 'Nager.Date public holiday API',
                'holidays' => [
                    ['date' => '2026-02-17', 'name' => 'Chinese New Year', 'type' => 'Special Non-Working Day'],
                    ['date' => '2026-03-20', 'name' => 'Eid al-Fitr', 'type' => 'Islamic Holiday'],
                    ['date' => '2026-12-25', 'name' => 'Christmas Day', 'type' => 'Regular Holiday'],
                ],
            ]);

        Http::assertSent(fn (ClientRequest $request): bool => $request->url() === 'https://date.nager.at/api/v3/PublicHolidays/2026/PH');
    }

    public function test_adds_official_islamic_holidays_from_calendarific_when_nager_has_none(): void
    {
        Cache::store('file')->forget('holidays:calendarific:PH:2025');
        config(['philippine_holidays.calendarific.key' => 'test-calendarific-key']);
        Http::preventStrayRequests();
        Http::fake([
            'https://date.nager.at/api/v3/PublicHolidays/2025/PH' => Http::response([
                ['date' => '2025-04-18', 'name' => 'Good Friday', 'types' => ['Public']],
            ]),
            'https://calendarific.com/api/v2/holidays*' => Http::response([
                'response' => [
                    'holidays' => [
                        ['name' => 'Eid al-Fitr', 'date' => ['iso' => '2025-04-01T00:00:00+08:00']],
                        ['name' => 'Eid al-Adha (Day 2)', 'date' => ['iso' => '2025-06-07T00:00:00+08:00']],
                        ['name' => 'Eid al-Adha (Tentative)', 'date' => ['iso' => '2025-06-06T00:00:00+08:00']],
                    ],
                ],
            ]),
        ]);

        $this->getJson('/api/holidays/2025')
            ->assertOk()
            ->assertJsonPath('notice', null)
            ->assertJsonPath('holidays.1', [
                'date' => '2025-04-18',
                'name' => 'Good Friday',
                'type' => 'Regular Holiday',
            ])
            ->assertJsonFragment([
                'date' => '2025-04-01',
                'name' => 'Eid al-Fitr',
                'type' => 'Islamic Holiday',
            ])
            ->assertJsonFragment([
                'date' => '2025-06-06',
                'name' => 'Eid al-Adha',
                'type' => 'Islamic Holiday',
            ])
            ->assertJsonMissing(['name' => 'Eid al-Adha (Day 2)']);

        Http::assertSentCount(2);
        Http::assertSent(fn (ClientRequest $request): bool => str_starts_with($request->url(), 'https://calendarific.com/api/v2/holidays?'));
    }

    public function test_returns_502_when_the_public_holiday_api_cannot_return_data(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://date.nager.at/api/v3/PublicHolidays/2024/PH' => Http::response([], 503),
        ]);

        $this->getJson('/api/holidays/2024')
            ->assertStatus(502)
            ->assertExactJson(['message' => 'No holiday data was returned for 2024.']);
    }

    public function test_returns_regular_holidays_with_a_notice_when_calendarific_is_not_configured(): void
    {
        config(['philippine_holidays.calendarific.key' => '']);
        Http::preventStrayRequests();
        Http::fake([
            'https://date.nager.at/api/v3/PublicHolidays/2023/PH' => Http::response([
                ['date' => '2023-12-25', 'name' => 'Christmas Day', 'types' => ['Public']],
            ]),
        ]);

        $this->getJson('/api/holidays/2023')
            ->assertOk()
            ->assertJsonPath('holidays', [
                ['date' => '2023-12-25', 'name' => 'Christmas Day', 'type' => 'Regular Holiday'],
            ])
            ->assertJsonPath('notice', 'Islamic holiday data is unavailable right now.');

        Http::assertSentCount(1);
    }

    public function test_returns_404_for_years_outside_the_required_range(): void
    {
        Http::preventStrayRequests();

        $this->getJson('/api/holidays/2019')->assertNotFound();
        $this->getJson('/api/holidays/2028')->assertNotFound();
        Http::assertNothingSent();
    }
}
