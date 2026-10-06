<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

class HolidayController extends Controller
{
    public function index(int $year): JsonResponse
    {
        $minimumYear = (int) config('philippine_holidays.min_year');
        $maximumYear = (int) config('philippine_holidays.max_year');

        abort_unless($year >= $minimumYear && $year <= $maximumYear, 404);

        $holidays = collect(config("philippine_holidays.years.{$year}", []))
            ->sortBy('date')
            ->values()
            ->all();

        $response = [
            'country' => config('philippine_holidays.country'),
            'year' => $year,
            'source' => 'Configured Philippine holiday reference calendar',
            'holidays' => $holidays,
        ];

        if ($year === 2027) {
            $response['notice'] = "The 2027 Eid'l Fitr and Eid'l Adha dates are estimates, as identified in the holiday reference list, and are not confirmed by presidential proclamation.";
        }

        return response()->json($response);
    }
}
