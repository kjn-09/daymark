<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class HolidayController extends Controller
{
    private const PUBLIC_HOLIDAYS_URL = 'https://date.nager.at/api/v3/PublicHolidays';

    private const REGULAR_HOLIDAY = 'Regular Holiday';

    private const SPECIAL_NON_WORKING_DAY = 'Special Non-Working Day';

    private const ISLAMIC_HOLIDAY = 'Islamic Holiday';

    public function index(int $year): JsonResponse
    {
        $minimumYear = (int) config('philippine_holidays.min_year');
        $maximumYear = (int) config('philippine_holidays.max_year');

        abort_unless($year >= $minimumYear && $year <= $maximumYear, 404);

        try {
            $response = Http::connectTimeout(3)
                ->timeout(10)
                ->acceptJson()
                ->get(self::PUBLIC_HOLIDAYS_URL.'/'.$year.'/'.config('philippine_holidays.country'));
        } catch (Throwable $exception) {
            Log::warning('Public holiday API request failed.', ['exception' => $exception->getMessage(), 'year' => $year]);

            return response()->json(['message' => 'The holiday service could not be reached. Please try again.'], 502);
        }

        $rows = $response->successful() ? $response->json() : null;

        if (! is_array($rows) || $rows === []) {
            Log::warning('Public holiday API returned an invalid response.', ['status' => $response->status(), 'year' => $year]);

            return response()->json(['message' => "No holiday data was returned for {$year}."], 502);
        }

        $holidays = collect($rows)
            ->filter(fn (mixed $holiday): bool => is_array($holiday)
                && isset($holiday['date'], $holiday['name'])
                && $this->isPublic($holiday))
            ->map(fn (array $holiday): array => [
                'date' => (string) $holiday['date'],
                'name' => (string) $holiday['name'],
                'type' => $this->classify((string) $holiday['name'], (string) ($holiday['localName'] ?? '')),
            ]);

        $officialIslamicHolidays = config("philippine_holidays.official_islamic_holidays.{$year}", []);
        $nagerIslamicTypes = $holidays
            ->filter(fn (array $holiday): bool => $holiday['type'] === self::ISLAMIC_HOLIDAY)
            ->map(fn (array $holiday): ?string => $this->islamicHolidayType($holiday['name']))
            ->filter()
            ->unique()
            ->all();
        $missingIslamicTypes = array_values(array_diff(
            array_keys($officialIslamicHolidays),
            $nagerIslamicTypes,
        ));

        $calendarificIslamicHolidays = $missingIslamicTypes !== []
            ? $this->islamicHolidays($year, $missingIslamicTypes, $officialIslamicHolidays)
            : [];

        $holidays = $holidays
            ->reject(fn (array $holiday): bool => $holiday['type'] === self::ISLAMIC_HOLIDAY)
            ->concat(collect($officialIslamicHolidays)->map(fn (string $date, string $name): array => [
                'date' => $date,
                'name' => $calendarificIslamicHolidays[$name]['name'] ?? $name,
                'type' => self::ISLAMIC_HOLIDAY,
            ]));

        $notice = $year === 2027
            ? "The official 2027 proclamation says Eid'l Fitr and Eid'l Adha dates will be declared separately. They are not included until officially proclaimed."
            : null;

        $response = [
            'country' => config('philippine_holidays.country'),
            'year' => $year,
            'source' => 'Nager.Date public holiday API with Eid dates reconciled to official Philippine proclamations',
            'holidays' => $holidays
                ->unique(fn (array $holiday): string => $holiday['date'].'|'.$holiday['name'])
                ->sortBy('date')
                ->values()
                ->all(),
        ];

        if ($notice !== null) {
            $response['notice'] = $notice;
        }

        return response()->json($response);
    }

    /**
     * @param  array<int, string>  $missingTypes
     * @param  array<string, string>  $officialHolidays
     * @return array<string, array{date: string, name: string}>
     */
    private function islamicHolidays(int $year, array $missingTypes, array $officialHolidays): array
    {
        $apiKey = (string) config('philippine_holidays.calendarific.key');

        if ($apiKey === '') {
            Log::warning('Calendarific API key is not configured.', ['year' => $year]);

            return [];
        }

        $cache = Cache::store('file');
        $cacheKey = 'holidays:calendarific:'.config('philippine_holidays.country').":{$year}";
        $cachedHolidays = $cache->get($cacheKey);

        if (is_array($cachedHolidays)) {
            return $this->matchOfficialIslamicHolidays($cachedHolidays, $missingTypes, $officialHolidays);
        }

        try {
            $response = Http::connectTimeout(3)
                ->timeout(10)
                ->acceptJson()
                ->get(config('philippine_holidays.calendarific.url'), [
                    'api_key' => $apiKey,
                    'country' => config('philippine_holidays.country'),
                    'year' => $year,
                ]);
        } catch (Throwable $exception) {
            Log::warning('Calendarific holiday API request failed.', [
                'exception' => str_replace($apiKey, '[hidden]', $exception->getMessage()),
                'year' => $year,
            ]);

            return [];
        }

        $rows = $response->successful() ? $response->json('response.holidays') : null;

        if (! is_array($rows)) {
            Log::warning('Calendarific holiday API returned an invalid response.', ['status' => $response->status(), 'year' => $year]);

            return [];
        }

        $calendarificHolidays = collect($rows)
            ->filter(fn (mixed $holiday): bool => is_array($holiday)
                && isset($holiday['name'], $holiday['date']['iso'])
                && $this->isOfficialIslamic((string) $holiday['name']))
            ->map(fn (array $holiday): array => [
                'date' => substr((string) $holiday['date']['iso'], 0, 10),
                'name' => (string) $holiday['name'],
            ])
            ->unique(fn (array $holiday): string => $holiday['date'].'|'.$holiday['name'])
            ->values()
            ->all();

        $cache->put($cacheKey, $calendarificHolidays, now()->addHours(6));

        return $this->matchOfficialIslamicHolidays($calendarificHolidays, $missingTypes, $officialHolidays);
    }

    /**
     * @param  array<int, array{date: string, name: string}>  $holidays
     * @param  array<int, string>  $missingTypes
     * @param  array<string, string>  $officialHolidays
     * @return array<string, array{date: string, name: string}>
     */
    private function matchOfficialIslamicHolidays(array $holidays, array $missingTypes, array $officialHolidays): array
    {
        $matched = [];

        foreach ($holidays as $holiday) {
            $type = $this->islamicHolidayType($holiday['name']);

            if (
                $type !== null
                && in_array($type, $missingTypes, true)
                && ($officialHolidays[$type] ?? null) === $holiday['date']
            ) {
                $matched[$type] = [
                    'date' => $holiday['date'],
                    'name' => trim((string) preg_replace('/\s*\((?:provisional|tentative)[^)]*\)/i', '', $holiday['name'])),
                ];
            }
        }

        return $matched;
    }

    private function islamicHolidayType(string $name): ?string
    {
        $letters = preg_replace('/[^a-z]/', '', Str::lower($name));

        if (! str_contains($letters, 'eid')) {
            return null;
        }

        return match (true) {
            str_contains($letters, 'fitr') => "Eid'l Fitr",
            str_contains($letters, 'adha') => "Eid'l Adha",
            default => null,
        };
    }

    private function isOfficialIslamic(string $name): bool
    {
        $normalizedName = Str::lower($name);

        if (preg_match('/day\s*2/', $normalizedName)) {
            return false;
        }

        return $this->islamicHolidayType($name) !== null;
    }

    /**
     * @param  array<string, mixed>  $holiday
     */
    private function isPublic(array $holiday): bool
    {
        $types = $holiday['types'] ?? ['Public'];

        return collect((array) $types)
            ->contains(fn (mixed $type): bool => strcasecmp((string) $type, 'Public') === 0);
    }

    private function classify(string $name, string $localName): string
    {
        $text = str_replace("'", '', Str::lower($name.' '.$localName));

        foreach (config('philippine_holidays.islamic') as $keyword) {
            if (str_contains($text, $keyword)) {
                return self::ISLAMIC_HOLIDAY;
            }
        }

        foreach (config('philippine_holidays.regular') as $keyword) {
            if (str_contains($text, $keyword)) {
                return self::REGULAR_HOLIDAY;
            }
        }

        return self::SPECIAL_NON_WORKING_DAY;
    }
}
