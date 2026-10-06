<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

class HolidayController extends Controller
{
    public function index(int $year): JsonResponse
    {
        abort_unless($year >= 2020 && $year <= 2027, 404);

        $holidays = [];

        foreach (config("philippine_holidays.{$year}", []) as $type => $dates) {
            foreach ($dates as $monthDay => $name) {
                $holidays[] = [
                    'date' => "{$year}-{$monthDay}",
                    'name' => $name,
                    'type' => $type,
                ];
            }
        }

        usort($holidays, fn (array $first, array $second): int => $first['date'] <=> $second['date']);

        $response = [
            'country' => 'PH',
            'year' => $year,
            'source' => 'Official Philippine holiday proclamations',
            'holidays' => $holidays,
        ];

        if ($year === 2027) {
            $response['notice'] = "The official 2027 proclamation says Eid'l Fitr and Eid'l Adha dates will be declared separately. They are not included until officially proclaimed.";
        }

        return response()->json($response);
    }
}
