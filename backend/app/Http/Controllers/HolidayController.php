<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class HolidayController extends Controller
{
    public function index(int $year): JsonResponse
    {
        abort_unless($year >= 2020 && $year <= 2027, 404);

        try {
            $response = Http::timeout(5)->get("https://date.nager.at/api/v3/PublicHolidays/{$year}/PH");
        } catch (Throwable $exception) {
            Log::error('Philippine holiday API request failed.', [
                'year' => $year,
                'error' => $exception->getMessage(),
            ]);

            return response()->json(['message' => "Could not fetch holidays for {$year}. Please try again."], 502);
        }

        if (! $response->successful()) {
            Log::error('Philippine holiday API returned an unsuccessful response.', [
                'year' => $year,
                'status' => $response->status(),
            ]);

            return response()->json(['message' => "Could not fetch holidays for {$year}. Please try again."], 502);
        }

        $apiHolidays = $response->json();

        if (! is_array($apiHolidays) || ! array_is_list($apiHolidays)) {
            Log::error('Philippine holiday API returned an invalid response.', ['year' => $year]);

            return response()->json(['message' => "Could not fetch holidays for {$year}. Please try again."], 502);
        }

        $holidays = [];

        foreach ($apiHolidays as $apiHoliday) {
            if (! is_array($apiHoliday)) {
                Log::error('Philippine holiday API returned an invalid holiday entry.', ['year' => $year]);

                return response()->json(['message' => "Could not fetch holidays for {$year}. Please try again."], 502);
            }

            $englishName = $apiHoliday['name'] ?? '';
            $localName = $apiHoliday['localName'] ?? $englishName;
            $date = $apiHoliday['date'] ?? '';
            $apiTypes = $apiHoliday['types'] ?? [];

            if (! is_string($localName) || ! is_string($englishName) || ! is_string($date) || ! is_array($apiTypes)) {
                Log::error('Philippine holiday API returned an invalid holiday entry.', ['year' => $year]);

                return response()->json(['message' => "Could not fetch holidays for {$year}. Please try again."], 502);
            }

            if ($this->isExcludedApiType($apiTypes)) {
                continue;
            }

            $type = $this->classifyHoliday([$localName, $englishName]);

            if ($type === null) {
                Log::error('Philippine holiday name has no configured category.', [
                    'year' => $year,
                    'local_name' => $localName,
                    'api_name' => $englishName,
                ]);

                return response()->json([
                    'message' => "Could not classify holiday \"{$englishName}\" for {$year}. The holiday data was not displayed.",
                ], 502);
            }

            $holidays[] = [
                'date' => $date,
                'name' => $englishName,
                'type' => $type,
            ];
        }

        return response()->json([
            'country' => 'PH',
            'year' => $year,
            'source' => 'Nager.Date Public Holidays API',
            'holidays' => $holidays,
        ]);
    }

    private function isExcludedApiType(array $apiTypes): bool
    {
        $excludedTypes = array_map(
            fn (string $type): string => $this->normalizeName($type),
            config('holidays.excluded_api_types', []),
        );

        foreach ($apiTypes as $apiType) {
            if (is_string($apiType) && in_array($this->normalizeName($apiType), $excludedTypes, true)) {
                return true;
            }
        }

        return false;
    }

    private function classifyHoliday(array $names): ?string
    {
        $normalizedNames = array_map(fn (string $name): string => $this->normalizeName($name), $names);

        foreach (config('holidays.categories', []) as $category => $patterns) {
            foreach ($patterns as $pattern) {
                $normalizedPattern = $this->normalizeName($pattern);

                foreach ($normalizedNames as $name) {
                    if (str_contains(" {$name} ", " {$normalizedPattern} ")) {
                        return $category;
                    }
                }
            }
        }

        return null;
    }

    private function normalizeName(string $name): string
    {
        return trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($name)));
    }
}
