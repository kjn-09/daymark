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
        config(['philippine_holidays.calendarific.key' => '']);
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
                'source' => 'Nager.Date public holiday API with Eid dates reconciled to official Philippine proclamations',
                'holidays' => [
                    ['date' => '2026-02-17', 'name' => 'Chinese New Year', 'type' => 'Special Non-Working Day'],
                    ['date' => '2026-03-20', 'name' => "Eid'l Fitr", 'type' => 'Islamic Holiday'],
                    ['date' => '2026-05-27', 'name' => "Eid'l Adha", 'type' => 'Islamic Holiday'],
                    ['date' => '2026-12-25', 'name' => 'Christmas Day', 'type' => 'Regular Holiday'],
                ],
            ]);

        Http::assertSent(fn (ClientRequest $request): bool => $request->url() === 'https://date.nager.at/api/v3/PublicHolidays/2026/PH');
    }

    public function test_calendarific_fills_a_missing_eid_when_nager_returns_the_other_eid(): void
    {
        Cache::store('file')->forget('holidays:calendarific:PH:2024');
        config(['philippine_holidays.calendarific.key' => 'test-calendarific-key']);
        Http::preventStrayRequests();
        Http::fake([
            'https://date.nager.at/api/v3/PublicHolidays/2024/PH' => Http::response([
                ['date' => '2024-06-17', 'name' => 'Eid al-Adha', 'types' => ['Public']],
            ]),
            'https://calendarific.com/api/v2/holidays*' => Http::response([
                'response' => [
                    'holidays' => [
                        ['name' => 'Eid al-Fitr (Tentative)', 'date' => ['iso' => '2024-04-10T00:00:00+08:00']],
                        ['name' => 'Eid al-Adha', 'date' => ['iso' => '2024-06-18T00:00:00+08:00']],
                        ['name' => 'Eid al-Adha (Day 2)', 'date' => ['iso' => '2024-06-18T00:00:00+08:00']],
                    ],
                ],
            ]),
        ]);

        $this->getJson('/api/holidays/2024')
            ->assertOk()
            ->assertJsonPath('notice', null)
            ->assertJsonFragment([
                'date' => '2024-04-10',
                'name' => 'Eid al-Fitr',
                'type' => 'Islamic Holiday',
            ])
            ->assertJsonFragment([
                'date' => '2024-06-17',
                'name' => "Eid'l Adha",
                'type' => 'Islamic Holiday',
            ])
            ->assertJsonMissing(['date' => '2024-06-18']);

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

    public function test_verified_eids_are_present_when_calendarific_is_not_configured(): void
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
                ['date' => '2023-04-21', 'name' => "Eid'l Fitr", 'type' => 'Islamic Holiday'],
                ['date' => '2023-06-28', 'name' => "Eid'l Adha", 'type' => 'Islamic Holiday'],
                ['date' => '2023-12-25', 'name' => 'Christmas Day', 'type' => 'Regular Holiday'],
            ])
            ->assertJsonMissingPath('notice');

        Http::assertSentCount(1);
    }

    public function test_verified_eids_remain_available_when_calendarific_fails(): void
    {
        Cache::store('file')->forget('holidays:calendarific:PH:2020');
        config(['philippine_holidays.calendarific.key' => 'test-calendarific-key']);
        Http::preventStrayRequests();
        Http::fake([
            'https://date.nager.at/api/v3/PublicHolidays/2020/PH' => Http::response([
                ['date' => '2020-07-31', 'name' => "Eid'l Adha", 'types' => ['Public']],
            ]),
            'https://calendarific.com/api/v2/holidays*' => Http::response([], 503),
        ]);

        $this->getJson('/api/holidays/2020')
            ->assertOk()
            ->assertJsonFragment([
                'date' => '2020-05-25',
                'name' => "Eid'l Fitr",
                'type' => 'Islamic Holiday',
            ])
            ->assertJsonFragment([
                'date' => '2020-07-31',
                'name' => "Eid'l Adha",
                'type' => 'Islamic Holiday',
            ])
            ->assertJsonMissingPath('notice');

        Http::assertSentCount(2);
    }

    public function test_unproclaimed_2027_eid_estimates_are_excluded_with_a_notice(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://date.nager.at/api/v3/PublicHolidays/2027/PH' => Http::response([
                ['date' => '2027-03-10', 'name' => 'Eid al-Fitr', 'types' => ['Public']],
                ['date' => '2027-05-17', 'name' => 'Eid al-Adha', 'types' => ['Public']],
                ['date' => '2027-12-25', 'name' => 'Christmas Day', 'types' => ['Public']],
            ]),
        ]);

        $this->getJson('/api/holidays/2027')
            ->assertOk()
            ->assertJsonPath('notice', "The official 2027 proclamation says Eid'l Fitr and Eid'l Adha dates will be declared separately. They are not included until officially proclaimed.")
            ->assertJsonMissing(['type' => 'Islamic Holiday'])
            ->assertJsonPath('holidays.0', [
                'date' => '2027-12-25',
                'name' => 'Christmas Day',
                'type' => 'Regular Holiday',
            ]);
    }

    public function test_returns_404_for_years_outside_the_required_range(): void
    {
        Http::preventStrayRequests();

        $this->getJson('/api/holidays/2019')->assertNotFound();
        $this->getJson('/api/holidays/2028')->assertNotFound();
        Http::assertNothingSent();
    }
}
