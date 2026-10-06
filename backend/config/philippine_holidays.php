<?php

return [
    'country' => 'PH',
    'min_year' => 2020,
    'max_year' => 2027,
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
