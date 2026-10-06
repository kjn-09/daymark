<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HolidayApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_supported_years_return_the_configured_holiday_reference(): void
    {
        Http::preventStrayRequests();

        $expectedDates = [
            2020 => '01-01,01-25,02-25,04-09,04-10,04-11,05-01,05-25,06-12,07-31,08-21,08-31,11-01,11-02,11-30,12-08,12-24,12-25,12-30,12-31',
            2021 => '01-01,02-12,02-25,04-01,04-02,04-03,04-09,05-01,05-13,06-12,07-20,08-21,08-30,11-01,11-30,12-08,12-25,12-30',
            2022 => '01-01,02-01,02-25,04-09,04-14,04-15,04-16,05-01,05-03,05-09,06-12,07-09,08-21,08-29,11-01,11-30,12-08,12-25,12-30',
            2023 => '01-01,01-02,01-22,02-24,04-06,04-07,04-08,04-10,04-21,05-01,06-12,06-28,08-21,08-28,11-01,11-02,11-27,12-08,12-25,12-30,12-31',
            2024 => '01-01,02-09,02-10,03-28,03-29,03-30,04-09,05-01,06-12,06-17,08-23,08-26,11-01,11-02,11-30,12-08,12-24,12-25,12-30,12-31',
            2025 => '01-01,01-29,04-01,04-09,04-17,04-18,04-19,05-01,06-06,06-12,08-21,08-25,10-31,11-01,11-30,12-08,12-24,12-25,12-30,12-31',
            2026 => '01-01,02-17,03-20,04-02,04-03,04-04,04-09,05-01,05-27,06-12,08-21,08-31,11-01,11-02,11-30,12-08,12-24,12-25,12-30,12-31',
            2027 => '01-01,01-02,02-06,03-10,03-25,03-26,03-27,04-09,05-01,05-17,06-12,08-21,08-30,11-01,11-02,11-30,12-08,12-24,12-25,12-30,12-31',
        ];

        foreach ($expectedDates as $year => $monthDays) {
            $holidays = $this->getJson("/api/holidays/{$year}")
                ->assertOk()
                ->assertJsonPath('country', 'PH')
                ->assertJsonPath('year', $year)
                ->assertJsonPath('source', 'Configured Philippine holiday reference calendar')
                ->json('holidays');

            $actualMonthDays = array_map(
                fn (string $date): string => substr($date, 5),
                array_column($holidays, 'date'),
            );
            $this->assertSame(
                explode(',', $monthDays),
                $actualMonthDays,
                "Holiday date inventory differs for {$year}.",
            );
        }

        Http::assertNothingSent();
    }

    public function test_special_dates_and_categories_match_the_reference_list(): void
    {
        $this->getJson('/api/holidays/2020')
            ->assertJsonFragment(['date' => '2020-04-09', 'name' => 'Araw ng Kagitingan & Maundy Thursday', 'type' => 'Regular Holiday'])
            ->assertJsonFragment(['date' => '2020-11-02', 'name' => "All Souls' Day", 'type' => 'Special Non-Working Day'])
            ->assertJsonMissing(['date' => '2020-10-31']);

        $this->getJson('/api/holidays/2021')
            ->assertJsonMissing(['date' => '2021-11-02'])
            ->assertJsonMissing(['date' => '2021-12-24'])
            ->assertJsonMissing(['date' => '2021-12-31']);

        $this->getJson('/api/holidays/2022')
            ->assertJsonFragment(['date' => '2022-05-09', 'name' => 'Special National Election Day', 'type' => 'Regular Holiday'])
            ->assertJsonMissing(['date' => '2022-12-31']);

        $this->getJson('/api/holidays/2023')
            ->assertJsonFragment(['date' => '2023-02-24', 'name' => 'EDSA People Power Revolution Anniversary', 'type' => 'Special Non-Working Day'])
            ->assertJsonFragment(['date' => '2023-11-27', 'name' => 'Bonifacio Day', 'type' => 'Regular Holiday'])
            ->assertJsonMissing(['date' => '2023-02-25'])
            ->assertJsonMissing(['date' => '2023-11-30']);

        $this->getJson('/api/holidays/2024')
            ->assertJsonFragment(['date' => '2024-04-09', 'name' => "Araw ng Kagitingan & Eid'l Fitr", 'type' => 'Regular Holiday'])
            ->assertJsonFragment(['date' => '2024-08-23', 'name' => 'Ninoy Aquino Day', 'type' => 'Special Non-Working Day'])
            ->assertJsonMissing(['date' => '2024-08-21']);

        $this->getJson('/api/holidays/2025')
            ->assertJsonFragment(['date' => '2025-04-01', 'name' => "Eid'l Fitr", 'type' => 'Regular Holiday'])
            ->assertJsonFragment(['date' => '2025-10-31', 'name' => "All Saints' Day Eve", 'type' => 'Special Non-Working Day'])
            ->assertJsonMissing(['date' => '2025-11-02']);
    }

    public function test_2027_eid_estimates_are_included_and_marked_estimated(): void
    {
        $this->getJson('/api/holidays/2027')
            ->assertOk()
            ->assertJsonPath('notice', "The 2027 Eid'l Fitr and Eid'l Adha dates are estimates, as identified in the holiday reference list, and are not confirmed by presidential proclamation.")
            ->assertJsonFragment([
                'date' => '2027-03-10',
                'name' => "Eid'l Fitr",
                'type' => 'Regular Holiday',
                'estimated' => true,
            ])
            ->assertJsonFragment([
                'date' => '2027-05-17',
                'name' => "Eid'l Adha",
                'type' => 'Regular Holiday',
                'estimated' => true,
            ])
            ->assertJsonFragment(['date' => '2027-01-02', 'type' => 'Special Non-Working Day']);
    }

    public function test_years_outside_the_supported_range_are_rejected(): void
    {
        Http::preventStrayRequests();

        $this->getJson('/api/holidays/2019')->assertNotFound();
        $this->getJson('/api/holidays/2028')->assertNotFound();

        Http::assertNothingSent();
    }
}
