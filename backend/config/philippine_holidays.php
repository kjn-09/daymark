<?php

return [
    'country' => 'PH',
    'min_year' => 2020,
    'max_year' => 2027,
   'official_islamic_holidays' => [
    2020 => ['Eid\'l Fitr' => '2020-05-25', 'Eid\'l Adha' => '2020-07-31'],
    2021 => ['Eid\'l Fitr' => '2021-05-13', 'Eid\'l Adha' => '2021-07-20'],
    2022 => ['Eid\'l Fitr' => '2022-05-03', 'Eid\'l Adha' => '2022-07-09'],
    2023 => ['Eid\'l Fitr' => '2023-04-21', 'Eid\'l Adha' => '2023-06-28'],
    2024 => ['Eid\'l Fitr' => '2024-04-10', 'Eid\'l Adha' => '2024-06-17'],
    2025 => ['Eid\'l Fitr' => '2025-04-01', 'Eid\'l Adha' => '2025-06-06'],
    2026 => ['Eid\'l Fitr' => '2026-03-20', 'Eid\'l Adha' => '2026-05-27'],
    2027 => ['Eid\'l Fitr' => '2027-03-10', 'Eid\'l Adha' => '2027-05-17'], // Estimated dates pending official proclamation
    ],
    'calendarific' => [
        'key' => env('CALENDARIFIC_API_KEY'),
        'url' => 'https://calendarific.com/api/v2/holidays',
    ],
    'islamic' => [
        'eid',
        'fitr',
        'adha',
        'ramadan',
        'islamic',
        'mawlid',
        'hijri',
        'muharram',
    ],
    'regular' => [
        'new years day',
        'maundy thursday',
        'good friday',
        'araw ng kagitingan',
        'day of valor',
        'labour day',
        'labor day',
        'araw ng paggawa',
        'independence day',
        'araw ng kasarinlan',
        'national heroes',
        'araw ng mga bayani',
        'bonifacio',
        'christmas day',
        'araw ng pasko',
        'rizal day',
    ],
];
