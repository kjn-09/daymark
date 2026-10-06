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
        $minimumYear = (int) config('philippine_holidays.min_year');
        $maximumYear = (int) config('philippine_holidays.max_year');

        abort_unless($year >= $minimumYear && $year <= $maximumYear, 404);

        $referenceHolidays = collect(config("philippine_holidays.years.{$year}", []));
        $referenceDates = $referenceHolidays->pluck('date')->unique()->sort()->values()->all();
        $upstreamAvailable = false;
        $upstreamMatchesReference = false;

        try {
            $upstreamResponse = Http::connectTimeout(3)
                ->timeout(10)
                ->acceptJson()
                ->get(config('philippine_holidays.public_holidays_url')."/{$year}/".config('philippine_holidays.country'));

            $upstreamRows = $upstreamResponse->successful() ? $upstreamResponse->json() : null;
            $upstreamDates = is_array($upstreamRows)
                ? collect($upstreamRows)
                    ->filter(fn (mixed $holiday): bool => is_array($holiday)
                        && isset($holiday['date'], $holiday['name'])
                        && is_string($holiday['date'])
                        && is_string($holiday['name'])
                        && preg_match("/^{$year}-\\d{2}-\\d{2}$/", (string) $holiday['date']) === 1)
                    ->pluck('date')
                    ->unique()
                    ->sort()
                    ->values()
                    ->all()
                : [];
            $upstreamAvailable = $upstreamDates !== [];
            $upstreamMatchesReference = $upstreamAvailable && $upstreamDates === $referenceDates;

            if (! $upstreamAvailable) {
                Log::warning('Public holiday API returned an invalid response; using the configured reference calendar.', [
                    'status' => $upstreamResponse->status(),
                    'year' => $year,
                ]);
            }
        } catch (Throwable $exception) {
            Log::warning('Public holiday API request failed; using the configured reference calendar.', [
                'exception' => $exception->getMessage(),
                'year' => $year,
            ]);
        }

        // The external API is checked dynamically, but the supplied reference inventory
        // remains authoritative for correcting missing, extra, or conflicting holidays.
        $holidays = $referenceHolidays
            ->sortBy('date')
            ->values()
            ->all();

        $response = [
            'country' => config('philippine_holidays.country'),
            'year' => $year,
            'source' => match (true) {
                ! $upstreamAvailable => 'Configured Philippine reference calendar (Nager.Date unavailable)',
                $upstreamMatchesReference => 'Configured Philippine reference calendar corroborated by Nager.Date',
                default => 'Configured Philippine reference calendar corrected against Nager.Date',
            },
            'holidays' => $holidays,
        ];

        if ($year === 2027) {
            $response['notice'] = "The 2027 Eid'l Fitr and Eid'l Adha dates are estimates, as identified in the holiday reference list, and are not confirmed by presidential proclamation.";
        }

        return response()->json($response);
    }
}
