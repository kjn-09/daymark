<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\LocalJsonStore;
use Carbon\Carbon;
use CommerceGuys\Addressing\AddressFormat\AddressFormatRepository;
use CommerceGuys\Addressing\Country\CountryRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;

class AuthController extends Controller
{
    private const INVALID_CREDENTIALS = 'Invalid email or password';

    private const LOGIN_LOCKOUT_MESSAGE = 'Your account has been temporarily locked. An unlock email has been sent to your registered email address. Please check your inbox.';

    public function __construct(private readonly LocalJsonStore $localState) {}

    public function postalCodeFormat(string $countryCode): JsonResponse
    {
        $countryCode = strtoupper($countryCode);
        if (! array_key_exists($countryCode, (new CountryRepository)->getList())) {
            abort(404);
        }

        return response()->json([
            'country_code' => $countryCode,
            'pattern' => (new AddressFormatRepository)->get($countryCode)->getPostalCodePattern(),
        ]);
    }

    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'first_name' => ['required', 'string', 'min:2', 'max:50', 'regex:/^[\pL\pM]+(?:[ \'-][\pL\pM]+)*$/u'],
            'last_name' => ['required', 'string', 'min:2', 'max:50', 'regex:/^[\pL\pM]+(?:[ \'-][\pL\pM]+)*$/u'],
            'middle_initial' => ['nullable', 'string', 'max:2', 'regex:/^[\pL\pM][.]?$/u'],
            'birthday' => ['required', 'string', 'regex:/^(0[1-9]|1[0-2])\/(0[1-9]|[12]\d|3[01])\/\d{4}$/', 'date_format:m/d/Y'],
            'email' => ['required', 'string', 'email:rfc', 'max:255', 'unique:users,email'],
            'mobile_number' => ['required', 'string', 'max:20'],
            'country' => ['required', 'string', 'max:100'],
            'country_code' => ['required', 'string', 'size:2', Rule::in(PhoneNumberUtil::getInstance()->getSupportedRegions())],
            'house_street' => ['required', 'string', 'max:255', 'regex:/^[\pL\pM\pN\s.,#\'\/()\-]+$/u'],
            'city' => ['required', 'string', 'max:100'],
            'state' => ['required', 'string', 'max:100'],
            'zip_code' => ['required', 'string', 'max:20'],
            'password' => ['required', 'string', 'min:12', 'confirmed', 'regex:/[a-z]/', 'regex:/[A-Z]/', 'regex:/[0-9]/', 'regex:/[^A-Za-z0-9]/'],
        ]);

        $birthday = Carbon::createFromFormat('!m/d/Y', $data['birthday'], 'Asia/Manila');
        $today = now('Asia/Manila');
        $age = $today->year - $birthday->year;
        $anniversaryMonth = $birthday->month;
        $anniversaryDay = $birthday->day;

        if ($birthday->month === 2 && $birthday->day === 29 && ! $today->isLeapYear()) {
            $anniversaryMonth = 3;
            $anniversaryDay = 1;
        }

        if (
            $today->month < $anniversaryMonth
            || ($today->month === $anniversaryMonth && $today->day < $anniversaryDay)
        ) {
            $age -= 1;
        }

        if ($age < 13) {
            throw ValidationException::withMessages(['birthday' => 'You must be at least 13 years old to register.']);
        }

        $data['birthday'] = $birthday->toDateString();

        $data['email'] = Str::lower($data['email']);

        if (! in_array(Str::after($data['email'], '@'), config('registration.public_email_domains'), true)) {
            throw ValidationException::withMessages(['email' => 'Use a personal email address from a supported public email provider.']);
        }

        $country = (new CountryRepository)->get($data['country_code']);
        $data['country'] = $country->getName();

        $phoneUtil = PhoneNumberUtil::getInstance();

        try {
            $parsedMobileNumber = $phoneUtil->parse($data['mobile_number'], $data['country_code']);
        } catch (NumberParseException|\InvalidArgumentException) {
            throw ValidationException::withMessages(['mobile_number' => 'Enter a valid mobile number for the selected country.']);
        }

        if (! $phoneUtil->isValidNumberForRegion($parsedMobileNumber, $data['country_code'])) {
            throw ValidationException::withMessages(['mobile_number' => 'Enter a valid mobile number for the selected country.']);
        }

        $postalCodePattern = (new AddressFormatRepository)->get($data['country_code'])->getPostalCodePattern();
        $postalCode = trim($data['zip_code']);
        $postalCodeIsValid = $postalCodePattern === null
            ? preg_match('/^[\pL\pN][\pL\pN -]{0,19}$/u', $postalCode) === 1
            : preg_match('/^(?:'.$postalCodePattern.')$/iu', $postalCode) === 1;

        if (! $postalCodeIsValid) {
            throw ValidationException::withMessages(['zip_code' => 'Enter a valid postal code for the selected country.']);
        }

        $data['zip_code'] = $postalCode;

        $mobileNumber = $phoneUtil->format($parsedMobileNumber, PhoneNumberFormat::E164);

        if (User::where('mobile_number', $mobileNumber)->exists()) {
            throw ValidationException::withMessages(['mobile_number' => 'This mobile number is already registered.']);
        }

        $user = DB::transaction(function () use ($data, $mobileNumber) {
            $user = User::create([
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'middle_initial' => $data['middle_initial'] ?? null,
                'birthday' => $data['birthday'],
                'email' => $data['email'],
                'password_hash' => $data['password'],
                'mobile_number' => $mobileNumber,
            ]);

            $user->address()->create([
                'house_street' => $data['house_street'],
                'country' => $data['country'],
                'city' => $data['city'],
                'state' => $data['state'],
                'zip_code' => $data['zip_code'],
            ]);

            return $user;
        });

        $user->sendEmailVerificationNotification();

        return response()->json([
            'message' => 'Your account has been created. Check your inbox for a verification link before signing in.',
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email:rfc'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', Str::lower($credentials['email']))->first();

        if (! $user) {
            throw ValidationException::withMessages(['email' => self::INVALID_CREDENTIALS]);
        }

        if ($user->is_locked) {
            return response()->json(['message' => self::LOGIN_LOCKOUT_MESSAGE], 423);
        }

        if (! $user->hasVerifiedEmail()) {
            throw ValidationException::withMessages(['email' => self::INVALID_CREDENTIALS]);
        }

        if (! Hash::check($credentials['password'], $user->password_hash)) {
            $attempts = $user->failed_login_attempts + 1;
            $user->forceFill([
                'failed_login_attempts' => $attempts,
                'is_locked' => $attempts >= 3,
                'lockout_until' => $attempts >= 3 ? now()->addMinutes(2) : null,
            ])->save();

            if ($attempts === 3) {
                if (! $this->sendUnlockEmail($user, $request)) {
                    return response()->json([
                        'message' => 'Your account has been locked, but the unlock email could not be sent. Please contact support.',
                    ], 503);
                }

                return response()->json(['message' => self::LOGIN_LOCKOUT_MESSAGE], 423);
            }

            throw ValidationException::withMessages(['email' => self::INVALID_CREDENTIALS]);
        }

        if (! $user->mobile_verified) {
            $delivery = $this->sendMobileCode($user);

            return response()->json([
                'message' => 'Enter the six-digit code sent to the mobile number on your account.',
                'mobile_verification_required' => true,
                'phone_hint' => substr($user->mobile_number, -4),
                'retry_after' => $delivery['retry_after'],
                'debug_code' => $delivery['debug_code'] ?? null,
            ], 403);
        }

        return $this->establishSession($request, $user);
    }

    public function verifyMobile(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email:rfc'],
            'password' => ['required', 'string'],
            'code' => ['required', 'digits:6'],
        ]);

        $user = User::where('email', Str::lower($credentials['email']))->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password_hash) || ! $user->hasVerifiedEmail()) {
            throw ValidationException::withMessages(['email' => 'The verification details are invalid.']);
        }

        if ($user->lockout_until && $user->lockout_until->isFuture()) {
            return response()->json(['message' => 'Your account is temporarily locked. Please try again after the two-minute security hold.'], 423);
        }

        $verification = DB::table('verification_tokens')
            ->where('user_id', $user->id)
            ->where('type', 'mobile_otp')
            ->first();

        if (! $verification || Carbon::parse($verification->expires_at)->isPast()) {
            throw ValidationException::withMessages(['code' => 'That code has expired. Request a new one to continue.']);
        }

        $attempts = $this->localState->read()['otp'][$user->id]['attempts'] ?? 0;

        if ($attempts >= 3 || ! hash_equals($verification->token_hash, hash_hmac('sha256', $credentials['code'], config('app.key')))) {
            if ($attempts < 3) {
                $attempts = $this->localState->update(function (array &$state) use ($user, $verification): int {
                    $state['otp'][$user->id] ??= [
                        'attempts' => 0,
                        'resend_until' => 0,
                        'expires_at' => Carbon::parse($verification->expires_at)->timestamp,
                    ];
                    $state['otp'][$user->id]['attempts']++;

                    return $state['otp'][$user->id]['attempts'];
                });

                if ($attempts >= 3) {
                    $user->forceFill([
                        'failed_login_attempts' => 3,
                        'is_locked' => true,
                        'lockout_until' => now()->addMinutes(2),
                    ])->save();

                    if (! $this->sendUnlockEmail($user, $request)) {
                        return response()->json([
                            'message' => 'Your account has been locked, but the unlock email could not be sent. Please contact support.',
                        ], 503);
                    }
                }
            }

            throw ValidationException::withMessages(['code' => 'That code is incorrect or has expired. Check it and try again.']);
        }

        $user->forceFill(['mobile_verified' => true])->save();
        DB::table('verification_tokens')->where('id', $verification->id)->delete();
        $this->localState->update(function (array &$state) use ($user): void {
            unset($state['otp'][$user->id]);
        });

        return $this->establishSession($request, $user);
    }

    public function resendMobileCode(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email:rfc'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', Str::lower($credentials['email']))->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password_hash) || ! $user->hasVerifiedEmail()) {
            throw ValidationException::withMessages(['email' => 'The verification details are invalid.']);
        }

        if ($user->lockout_until && $user->lockout_until->isFuture()) {
            return response()->json(['message' => 'Your account is temporarily locked. Please try again after the two-minute security hold.'], 423);
        }

        if (! $user->mobile_verified) {
            $delivery = $this->sendMobileCode($user);

            if (! $delivery['sent']) {
                return response()->json([
                    'message' => "Please wait {$delivery['retry_after']} seconds before requesting another code.",
                    'retry_after' => $delivery['retry_after'],
                ], 429);
            }

            return response()->json([
                'message' => 'A new verification code has been sent.',
                'retry_after' => $delivery['retry_after'],
                'debug_code' => $delivery['debug_code'] ?? null,
            ]);
        }

        return response()->json(['message' => 'A new verification code has been sent.', 'retry_after' => 60]);
    }

    private function establishSession(Request $request, User $user): JsonResponse
    {
        $request->session()->regenerate();
        $request->session()->put('auth_user_id', $user->id);

        $user->forceFill([
            'failed_login_attempts' => 0,
            'is_locked' => false,
            'lockout_until' => null,
        ])->save();
        DB::table('verification_tokens')
            ->where('user_id', $user->id)
            ->where('type', 'account_unlock')
            ->delete();

        return response()->json([
            'user' => $user->load('address'),
        ]);
    }

    private function sendMobileCode(User $user): array
    {
        $code = (string) random_int(100000, 999999);
        $now = now();
        $expiresAt = $now->copy()->addMinutes(5);
        $countryName = $user->address()->value('country');
        $countryCode = null;

        foreach ((new CountryRepository)->getAll('en') as $country) {
            if (strcasecmp($country->getName(), $countryName) === 0) {
                $countryCode = $country->getCountryCode();
                break;
            }
        }

        $timezones = $countryCode
            ? \DateTimeZone::listIdentifiers(\DateTimeZone::PER_COUNTRY, $countryCode)
            : [];
        $timezone = $timezones[0] ?? null;

        if (! $timezone) {
            throw ValidationException::withMessages(['mobile_number' => 'Mobile verification is temporarily unavailable. Please try again later.']);
        }

        $reservation = $this->localState->update(function (array &$state) use ($user, $now, $expiresAt): array {
            $otp = $state['otp'][$user->id] ?? [];

            if (($otp['resend_until'] ?? 0) > $now->timestamp) {
                return [
                    'sent' => false,
                    'retry_after' => $otp['resend_until'] - $now->timestamp,
                ];
            }

            $state['otp'][$user->id] = [
                'attempts' => $otp['attempts'] ?? 0,
                'resend_until' => $now->copy()->addSeconds(60)->timestamp,
                'expires_at' => $expiresAt->timestamp,
            ];

            return ['sent' => true, 'retry_after' => 60];
        });

        if (! $reservation['sent']) {
            return $reservation;
        }

        DB::table('verification_tokens')->updateOrInsert(
            ['user_id' => $user->id, 'type' => 'mobile_otp'],
            [
                'id' => (string) Str::uuid(),
                'token_hash' => hash_hmac('sha256', $code, config('app.key')),
                'expires_at' => $expiresAt,
            ],
        );

        $apiKey = config('services.textbee.api_key');
        $gatewayId = config('services.textbee.gateway_id');

        if ($apiKey && $gatewayId) {
            $response = Http::withHeaders(['x-api-key' => $apiKey])->timeout(8)->post(
                'https://api.textbee.dev/api/v1/gateway/send-sms',
                [
                    'deviceId' => $gatewayId,
                    'recipients' => [$user->mobile_number],
                    'message' => 'Your code is '.$code.'. It expires at '.$expiresAt->copy()->setTimezone($timezone)->format('g:i A').' '.$timezone.'.',
                ],
            );

            if (! $response->successful()) {
                Log::error('Mobile verification SMS could not be delivered.', ['user_id' => $user->id, 'status' => $response->status()]);
                throw ValidationException::withMessages(['mobile_number' => 'The verification code could not be sent. Please try again shortly.']);
            }
        } elseif (app()->environment('local', 'testing')) {
            Log::info('Local mobile verification code.', ['user_id' => $user->id, 'code' => $code]);
        } else {
            Log::error('TextBee SMS credentials are missing.', ['user_id' => $user->id]);
            throw ValidationException::withMessages(['mobile_number' => 'Mobile verification is temporarily unavailable. Please try again later.']);
        }

        return [
            'retry_after' => 60,
            'sent' => true,
            'debug_code' => app()->environment('local') ? $code : null,
        ];
    }

    private function sendUnlockEmail(User $user, Request $request): bool
    {
        $this->localState->update(function (array &$state) use ($user): void {
            unset($state['otp'][$user->id]);
        });

        $token = null;

        try {
            $token = bin2hex(random_bytes(32));
            $now = now();

            DB::table('verification_tokens')->updateOrInsert(
                ['user_id' => $user->id, 'type' => 'account_unlock'],
                [
                    'id' => (string) Str::uuid(),
                    'token_hash' => hash_hmac('sha256', $token, config('app.key')),
                    'expires_at' => $now->copy()->addMinutes(15),
                ],
            );
            $urlGenerator = clone URL::getFacadeRoot();
            $urlGenerator->forceRootUrl(config('app.url'));
            $url = $urlGenerator->temporarySignedRoute('account.unlock', $now->copy()->addMinutes(15), [
                'id' => $user->id,
                'token' => $token,
            ]);
            Mail::raw(
                "We noticed three unsuccessful sign-in attempts. Your account is on a two-minute security hold. After the hold, use this secure link to unlock it: {$url}",
                fn ($message) => $message->to($user->email)->subject('Daymark account security alert'),
            );

            return true;
        } catch (\Throwable $error) {
            if ($token !== null) {
                DB::table('verification_tokens')
                    ->where('user_id', $user->id)
                    ->where('type', 'account_unlock')
                    ->delete();
            }

            Log::error('Account unlock email could not be sent.', ['user_id' => $user->id, 'error' => $error->getMessage()]);

            return false;
        }
    }

    public function logout(Request $request): JsonResponse
    {
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Signed out.']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($request->attributes->get('api_user')->load('address'));
    }

    public function accounts(): JsonResponse
    {
        $accounts = User::query()
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get(['first_name', 'last_name', 'email', 'email_verified_at', 'mobile_verified'])
            ->map(fn (User $user): array => [
                'name' => trim($user->first_name.' '.$user->last_name),
                'email' => $user->email,
                'email_verified' => $user->email_verified_at !== null && $user->mobile_verified,
            ]);

        return response()->json(['accounts' => $accounts]);
    }
}
