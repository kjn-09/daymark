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

        $notice = null;

        if (! $holidays->contains('type', self::ISLAMIC_HOLIDAY)) {
            [$islamicHolidays, $notice] = $this->islamicHolidays($year);
            $holidays = $holidays->concat($islamicHolidays);
        }

        $response = [
            'country' => config('philippine_holidays.country'),
            'year' => $year,
            'source' => 'Nager.Date public holiday API',
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
     * @return array{0: array<int, array{date: string, name: string, type: string}>, 1: ?string}
     */
    private function islamicHolidays(int $year): array
    {
        $apiKey = (string) config('philippine_holidays.calendarific.key');

        if ($apiKey === '') {
            Log::warning('Calendarific API key is not configured.', ['year' => $year]);

            return [[], 'Islamic holiday data is unavailable right now.'];
        }

        $cache = Cache::store('file');
        $cacheKey = 'holidays:calendarific:'.config('philippine_holidays.country').":{$year}";
        $cachedHolidays = $cache->get($cacheKey);

        if (is_array($cachedHolidays) && $cachedHolidays !== []) {
            return [$cachedHolidays, null];
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

            return [[], 'Islamic holiday data is unavailable right now.'];
        }

        $rows = $response->successful() ? $response->json('response.holidays') : null;

        if (! is_array($rows)) {
            Log::warning('Calendarific holiday API returned an invalid response.', ['status' => $response->status(), 'year' => $year]);

            return [[], 'Islamic holiday data is unavailable right now.'];
        }

        $holidays = collect($rows)
            ->filter(fn (mixed $holiday): bool => is_array($holiday)
                && isset($holiday['name'], $holiday['date']['iso'])
                && $this->isOfficialIslamic((string) $holiday['name']))
            ->map(fn (array $holiday): array => [
                'date' => substr((string) $holiday['date']['iso'], 0, 10),
                'name' => trim((string) preg_replace('/\s*\((?:provisional|tentative)[^)]*\)/i', '', (string) $holiday['name'])),
                'type' => self::ISLAMIC_HOLIDAY,
            ])
            ->unique(fn (array $holiday): string => $holiday['date'].'|'.$holiday['name'])
            ->values()
            ->all();

        if ($holidays === []) {
            return [[], "No Islamic holiday data was returned for {$year}."];
        }

        $cache->put($cacheKey, $holidays, now()->addHours(6));

        return [$holidays, null];
    }

    private function isOfficialIslamic(string $name): bool
    {
        $normalizedName = Str::lower($name);

        if (preg_match('/day\s*2/', $normalizedName)) {
            return false;
        }

        $letters = preg_replace('/[^a-z]/', '', $normalizedName);

        return str_contains($letters, 'eid')
            && (str_contains($letters, 'fitr') || str_contains($letters, 'adha'));
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
