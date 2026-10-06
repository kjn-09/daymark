<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HolidayApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_supported_year_returns_the_verified_proclamation_calendar(): void
    {
        $expectedCounts = [
            2020 => 20,
            2021 => 18,
            2022 => 19,
            2023 => 20,
            2024 => 20,
            2025 => 21,
            2026 => 20,
            2027 => 18,
        ];

        foreach ($expectedCounts as $year => $expectedCount) {
            $response = $this->getJson("/api/holidays/{$year}")
                ->assertOk()
                ->assertJsonPath('country', 'PH')
                ->assertJsonPath('year', $year)
                ->assertJsonPath('source', 'Official Philippine holiday proclamations');

            $holidays = $response->json('holidays');
            $this->assertCount($expectedCount, $holidays, "Unexpected holiday count for {$year}.");
            $this->assertSame(
                collect($holidays)->pluck('date')->sort()->values()->all(),
                collect($holidays)->pluck('date')->values()->all(),
                "Holidays are not date-sorted for {$year}.",
            );
            $this->assertSame(
                ['date', 'name', 'type'],
                array_keys($holidays[0]),
                "Unexpected holiday response shape for {$year}.",
            );
        }
    }

    public function test_each_year_matches_the_complete_verified_date_inventory(): void
    {
        $expectedDates = [
            2020 => ['01-01', '01-25', '02-25', '04-09', '04-10', '04-11', '05-01', '05-25', '06-12', '07-31', '08-21', '08-31', '11-01', '11-02', '11-30', '12-08', '12-24', '12-25', '12-30', '12-31'],
            2021 => ['01-01', '02-12', '02-25', '04-01', '04-02', '04-03', '04-09', '05-01', '05-13', '06-12', '07-20', '08-21', '08-30', '11-01', '11-30', '12-08', '12-25', '12-30'],
            2022 => ['01-01', '02-01', '02-25', '04-09', '04-14', '04-15', '04-16', '05-01', '05-03', '05-09', '06-12', '07-09', '08-21', '08-29', '11-01', '11-30', '12-08', '12-25', '12-30'],
            2023 => ['01-01', '01-02', '02-24', '04-06', '04-07', '04-08', '04-10', '04-21', '05-01', '06-12', '06-28', '08-21', '08-28', '11-01', '11-02', '11-27', '12-08', '12-25', '12-30', '12-31'],
            2024 => ['01-01', '02-10', '03-28', '03-29', '03-30', '04-09', '04-10', '05-01', '06-12', '06-17', '08-21', '08-26', '11-01', '11-02', '11-30', '12-08', '12-24', '12-25', '12-30', '12-31'],
            2025 => ['01-01', '01-29', '04-01', '04-09', '04-17', '04-18', '04-19', '05-01', '05-12', '06-06', '06-12', '08-21', '08-25', '10-31', '11-01', '11-30', '12-08', '12-24', '12-25', '12-30', '12-31'],
            2026 => ['01-01', '02-17', '03-20', '04-02', '04-03', '04-04', '04-09', '05-01', '05-27', '06-12', '08-21', '08-31', '11-01', '11-02', '11-30', '12-08', '12-24', '12-25', '12-30', '12-31'],
            2027 => ['01-01', '02-06', '03-25', '03-26', '03-27', '04-09', '05-01', '06-12', '08-21', '08-30', '11-01', '11-02', '11-30', '12-08', '12-24', '12-25', '12-30', '12-31'],
        ];

        foreach ($expectedDates as $year => $monthDays) {
            $actualDates = collect($this->getJson("/api/holidays/{$year}")->assertOk()->json('holidays'))
                ->pluck('date')
                ->map(fn (string $date): string => substr($date, 5))
                ->all();

            $this->assertSame($monthDays, $actualDates, "The official date inventory differs for {$year}.");
        }
    }

    public function test_supplemental_and_islamic_proclamations_are_included(): void
    {
        $this->getJson('/api/holidays/2020')
            ->assertOk()
            ->assertJsonFragment(['date' => '2020-07-31', 'name' => "Eid'l Adha", 'type' => 'Islamic Holiday'])
            ->assertJsonFragment(['date' => '2020-11-02', 'name' => 'Additional Special Non-Working Day', 'type' => 'Special Non-Working Day'])
            ->assertJsonFragment(['date' => '2020-12-24', 'name' => 'Additional Special Non-Working Day', 'type' => 'Special Non-Working Day']);

        $this->getJson('/api/holidays/2022')
            ->assertOk()
            ->assertJsonFragment(['date' => '2022-05-09', 'name' => 'National Election Day', 'type' => 'Special Non-Working Day'])
            ->assertJsonFragment(['date' => '2022-07-09', 'name' => "Eid'l Adha", 'type' => 'Islamic Holiday']);

        $this->getJson('/api/holidays/2023')
            ->assertOk()
            ->assertJsonFragment(['date' => '2023-02-24', 'name' => 'EDSA People Power Revolution Anniversary', 'type' => 'Special Non-Working Day'])
            ->assertJsonFragment(['date' => '2023-11-27', 'name' => 'Bonifacio Day', 'type' => 'Regular Holiday']);

        $this->getJson('/api/holidays/2025')
            ->assertOk()
            ->assertJsonFragment(['date' => '2025-05-12', 'name' => 'National Election Day', 'type' => 'Special Non-Working Day'])
            ->assertJsonFragment(['date' => '2025-06-06', 'name' => "Eid'l Adha", 'type' => 'Islamic Holiday'])
            ->assertJsonFragment(['date' => '2025-10-31', 'name' => "All Saints' Day Eve", 'type' => 'Special Non-Working Day']);
    }

    public function test_special_working_days_and_unproclaimed_future_eid_dates_are_not_listed(): void
    {
        foreach ([
            2021 => ['2021-11-02', '2021-12-24', '2021-12-31'],
            2022 => ['2022-11-02', '2022-12-24', '2022-12-31'],
            2025 => ['2025-02-25'],
            2026 => ['2026-02-25'],
            2027 => ['2027-02-25'],
        ] as $year => $excludedDates) {
            $holidays = $this->getJson("/api/holidays/{$year}")->assertOk()->json('holidays');
            $dates = array_column($holidays, 'date');

            foreach ($excludedDates as $excludedDate) {
                $this->assertNotContains($excludedDate, $dates, "{$excludedDate} is a special working day.");
            }
        }

        $holidays2027 = $this->getJson('/api/holidays/2027')->assertOk()->json('holidays');
        $this->assertNotContains('Islamic Holiday', array_column($holidays2027, 'type'));
        $this->getJson('/api/holidays/2027')
            ->assertJsonPath('notice', "The official 2027 proclamation says Eid'l Fitr and Eid'l Adha dates will be declared separately. They are not included until officially proclaimed.");
    }

    public function test_years_outside_the_supported_range_are_rejected(): void
    {
        $this->getJson('/api/holidays/2019')->assertNotFound();
        $this->getJson('/api/holidays/2028')->assertNotFound();
    }
}
