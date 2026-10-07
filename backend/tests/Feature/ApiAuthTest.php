<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\LocalJsonStore;
use Carbon\Carbon;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class ApiAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_creates_account_address_and_verification_email(): void
    {
        $this->freezeTime();
        config(['app.url' => 'https://localhost']);
        Notification::fake();
        $trustedHttpsProxy = [
            'REMOTE_ADDR' => '127.0.0.1',
            'SERVER_PORT' => '443',
            'HTTPS' => 'off',
            'HTTP_HOST' => 'localhost',
            'HTTP_X_FORWARDED_HOST' => 'localhost',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ];
        $verificationUrl = null;

        $response = $this->withServerVariables($trustedHttpsProxy)->postJson('/api/register', [
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'middle_initial' => 'A.',
            'birthday' => '04/17/1995',
            'email' => 'maria.santos@gmail.com',
            'mobile_number' => '09171234567',
            'country' => 'Philippines',
            'country_code' => 'PH',
            'house_street' => '12 Sampaguita Street',
            'city' => 'Quezon City',
            'state' => 'Metro Manila',
            'zip_code' => '1100',
            'password' => 'SafePassword!482',
            'password_confirmation' => 'SafePassword!482',
        ]);

        $response->assertCreated();
        $user = User::where('email', 'maria.santos@gmail.com')->firstOrFail();

        $this->assertDatabaseHas('addresses', [
            'user_id' => $user->id,
            'city' => 'Quezon City',
            'zip_code' => '1100',
        ]);
        Notification::assertSentTo($user, VerifyEmail::class, function (VerifyEmail $notification) use ($user, &$verificationUrl): bool {
            $message = $notification->toMail($user);
            $html = (string) $message->render();
            preg_match('/href="([^"]*\/email\/verify\/[^\"]+)"/', $html, $emailLinkMatches);
            $encodedEmailLink = $emailLinkMatches[1] ?? '';
            $verificationUrl = html_entity_decode($encodedEmailLink, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            parse_str((string) parse_url($message->actionUrl, PHP_URL_QUERY), $query);

            $this->assertStringContainsString('&amp;', $encodedEmailLink);
            $this->assertSame($message->actionUrl, $verificationUrl);
            $this->assertSame('Action Required: Verify your email address for Daymark', $message->subject);
            $this->assertSame('Dear Maria,', $message->greeting);
            $this->assertSame([
                'Thank you for registering with Daymark. We are thrilled to welcome you to our community.',
                'To ensure the security of your account and complete your registration, please verify your email address by clicking the secure link below:',
            ], $message->introLines);
            $this->assertSame('Verify My Email Address', $message->actionText);
            $this->assertSame(now()->addHours(24)->timestamp, (int) $query['expires']);
            $this->assertSame([
                'If you did not initiate this request, please disregard this message. This link will expire in 24 hours for your protection.',
            ], $message->outroLines);
            $this->assertSame('Warm regards, The Daymark Security Team', $message->salutation);

            return true;
        });

        $this->assertNull($user->email_verified_at);
        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'SafePassword!482',
        ])->assertUnprocessable()->assertJsonValidationErrors([
            'email' => 'Invalid email or password',
        ]);

        $this->assertStringStartsWith('https://localhost/email/verify/'.$user->id.'/', $verificationUrl);
        $this->assertStringNotContainsString('&amp;', $verificationUrl);

        $this->withServerVariables($trustedHttpsProxy)
            ->get($verificationUrl)
            ->assertOk()
            ->assertSee('Your account has been verified.')
            ->assertSee('Your email address has been verified.')
            ->assertSee('Go back to Daymark');
        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_registration_accepts_leap_day_only_in_leap_year_and_counts_age_on_march_first(): void
    {
        Notification::fake();
        $this->travelTo(Carbon::parse('2021-02-28 12:00:00', 'Asia/Manila'));

        $registration = [
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'birthday' => '02/29/2008',
            'email' => 'maria.leapday@gmail.com',
            'mobile_number' => '09171234567',
            'country' => 'Philippines',
            'country_code' => 'PH',
            'house_street' => '12 Sampaguita Street',
            'city' => 'Quezon City',
            'state' => 'Metro Manila',
            'zip_code' => '1100',
            'password' => 'SafePassword!482',
            'password_confirmation' => 'SafePassword!482',
        ];

        $this->postJson('/api/register', array_merge($registration, [
            'birthday' => '02/29/2023',
            'email' => 'maria.invalid-leap@gmail.com',
        ]))->assertUnprocessable()->assertJsonValidationErrors('birthday');

        $this->postJson('/api/register', $registration)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['birthday' => 'You must be at least 13 years old to register.']);

        $this->travel(1)->day();

        $this->postJson('/api/register', $registration)->assertCreated();
        $user = User::where('email', 'maria.leapday@gmail.com')->firstOrFail();
        $this->assertSame('2008-02-29', $user->birthday->toDateString());
    }

    public function test_email_verification_link_uses_the_configured_app_url_behind_a_proxy(): void
    {
        config(['app.url' => 'https://daymark-test.onrender.com']);
        Notification::fake();

        $trustedHttpsProxy = [
            'REMOTE_ADDR' => '127.0.0.1',
            'SERVER_PORT' => '8000',
            'HTTPS' => 'off',
            'HTTP_HOST' => '127.0.0.1:8000',
            'HTTP_X_FORWARDED_HOST' => 'daymark-test.onrender.com',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ];

        $this->withServerVariables($trustedHttpsProxy)->postJson('/api/register', [
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'birthday' => '04/17/1995',
            'email' => 'maria.render@gmail.com',
            'mobile_number' => '09171234567',
            'country' => 'Philippines',
            'country_code' => 'PH',
            'house_street' => '12 Sampaguita Street',
            'city' => 'Quezon City',
            'state' => 'Metro Manila',
            'zip_code' => '1100',
            'password' => 'SafePassword!482',
            'password_confirmation' => 'SafePassword!482',
        ])->assertCreated();

        $user = User::where('email', 'maria.render@gmail.com')->firstOrFail();
        $verificationUrl = null;

        Notification::assertSentTo($user, VerifyEmail::class, function (VerifyEmail $notification) use ($user, &$verificationUrl): bool {
            $verificationUrl = $notification->toMail($user)->actionUrl;

            return true;
        });

        $this->assertStringStartsWith('https://daymark-test.onrender.com/email/verify/'.$user->id.'/', $verificationUrl);
        $this->withServerVariables($trustedHttpsProxy)
            ->get($verificationUrl)
            ->assertOk()
            ->assertSee('href="https://daymark-test.onrender.com/"', false)
            ->assertSee('Your account has been verified.');
        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_unlock_link_uses_the_configured_app_url_behind_a_proxy(): void
    {
        config(['app.url' => 'https://localhost']);
        $this->freezeTime();
        $user = $this->makeUnlockTestUser('maria.local-proxy@gmail.com');
        $proxy = [
            'REMOTE_ADDR' => '127.0.0.1',
            'SERVER_PORT' => '8000',
            'HTTPS' => 'off',
            'HTTP_HOST' => '127.0.0.1:8000',
            'HTTP_X_FORWARDED_HOST' => 'localhost',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ];
        $unlockUrl = $this->issueUnlockUrl($user, $proxy);

        $this->assertStringStartsWith('https://localhost/account/unlock/'.$user->id.'?', $unlockUrl);
        $this->travel(2)->minutes();
        $this->withServerVariables($proxy)->get($unlockUrl)->assertRedirect('https://localhost/?unlocked=1');
        $this->assertFalse($user->fresh()->is_locked);
        $this->assertDatabaseMissing('verification_tokens', [
            'user_id' => $user->id,
            'type' => 'account_unlock',
        ]);
    }

    public function test_unlock_link_ignores_the_forwarded_host_for_its_signed_url(): void
    {
        config(['app.url' => 'https://daymark-test.onrender.com']);
        $this->freezeTime();
        $user = $this->makeUnlockTestUser('maria.unlock-render@gmail.com');
        $proxy = [
            'REMOTE_ADDR' => '127.0.0.1',
            'SERVER_PORT' => '8000',
            'HTTPS' => 'off',
            'HTTP_HOST' => '127.0.0.1:8000',
            'HTTP_X_FORWARDED_HOST' => 'proxy.example.test',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ];
        $unlockUrl = $this->issueUnlockUrl($user, $proxy);

        $this->assertStringStartsWith('https://daymark-test.onrender.com/account/unlock/'.$user->id.'?', $unlockUrl);
        $proxy['HTTP_X_FORWARDED_HOST'] = 'daymark-test.onrender.com';
        $this->travel(2)->minutes();
        $this->withServerVariables($proxy)->get($unlockUrl)->assertRedirect('https://daymark-test.onrender.com/?unlocked=1');
        $this->assertFalse($user->fresh()->is_locked);
        $this->assertDatabaseMissing('verification_tokens', [
            'user_id' => $user->id,
            'type' => 'account_unlock',
        ]);
    }

    public function test_registration_rejects_a_custom_email_domain(): void
    {
        $this->postJson('/api/register', [
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'birthday' => '04/17/1995',
            'email' => 'maria@example.com',
            'mobile_number' => '09171234567',
            'country' => 'Philippines',
            'country_code' => 'PH',
            'house_street' => '12 Sampaguita Street',
            'city' => 'Quezon City',
            'state' => 'Metro Manila',
            'zip_code' => '1100',
            'password' => 'SafePassword!482',
            'password_confirmation' => 'SafePassword!482',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_registration_rejects_public_email_providers_not_named_in_the_specification(): void
    {
        foreach (['maria@proton.me', 'maria@fastmail.com', 'maria@aol.com'] as $email) {
            $this->postJson('/api/register', [
                'first_name' => 'Maria',
                'last_name' => 'Santos',
                'birthday' => '04/17/1995',
                'email' => $email,
                'mobile_number' => '09171234567',
                'country' => 'Philippines',
                'country_code' => 'PH',
                'house_street' => '12 Sampaguita Street',
                'city' => 'Quezon City',
                'state' => 'Metro Manila',
                'zip_code' => '1100',
                'password' => 'SafePassword!482',
                'password_confirmation' => 'SafePassword!482',
            ])->assertUnprocessable()->assertJsonValidationErrors('email');
        }
    }

    public function test_registration_rejects_non_formatted_birthdays_and_short_names(): void
    {
        $this->postJson('/api/register', [
            'first_name' => 'M',
            'last_name' => 'Santos',
            'birthday' => '1995-04-17',
            'email' => 'maria.santos@gmail.com',
            'mobile_number' => '09171234567',
            'country' => 'Philippines',
            'country_code' => 'PH',
            'house_street' => '12 Sampaguita Street',
            'city' => 'Quezon City',
            'state' => 'Metro Manila',
            'zip_code' => '1100',
            'password' => 'SafePassword!482',
            'password_confirmation' => 'SafePassword!482',
        ])->assertUnprocessable()->assertJsonValidationErrors(['first_name', 'birthday']);
    }

    public function test_registration_is_limited_to_five_requests_per_hour_per_ip(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/register', [])->assertUnprocessable();
        }

        $this->postJson('/api/register', [])
            ->assertTooManyRequests()
            ->assertJsonPath('message', 'Registration limit has been hit. Please wait for an hour and try to register again.');

        $this->travel(61)->minutes();

        $this->postJson('/api/register', [])->assertUnprocessable();
    }

    public function test_registration_rate_limit_uses_the_forwarded_client_ip(): void
    {
        $this->withServerVariables([
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.70',
        ])->postJson('/api/register', [])->assertUnprocessable();

        $registrationState = app(LocalJsonStore::class)->read()['registration'];

        $this->assertArrayHasKey(hash('sha256', '203.0.113.70'), $registrationState);
    }

    public function test_csrf_token_endpoint_starts_a_registration_session(): void
    {
        $this->getJson('/csrf-token')
            ->assertOk()
            ->assertJsonStructure(['token']);
    }

    public function test_schema_contains_only_the_specified_application_tables(): void
    {
        $this->assertEqualsCanonicalizing(
            ['users', 'addresses', 'verification_tokens', 'migrations'],
            array_map(fn (string $table): string => Str::afterLast($table, '.'), Schema::getTableListing()),
        );
        $this->assertFalse(Schema::hasColumn('verification_tokens', 'attempts'));
        $this->assertFalse(Schema::hasColumn('verification_tokens', 'available_at'));

        if (DB::connection()->getDriverName() === 'pgsql') {
            $enumValues = DB::select(
                "SELECT enumlabel
                 FROM pg_enum
                 JOIN pg_type ON pg_enum.enumtypid = pg_type.oid
                 JOIN pg_namespace ON pg_type.typnamespace = pg_namespace.oid
                 WHERE pg_type.typname = 'verification_token_type'
                   AND pg_namespace.nspname = current_schema()
                 ORDER BY enumsortorder",
            );
            $this->assertSame(
                ['email_verify', 'mobile_otp', 'password_reset', 'account_unlock'],
                array_column($enumValues, 'enumlabel'),
            );
        }

        if (DB::connection()->getDriverName() === 'sqlite') {
            $columns = collect(DB::select('PRAGMA table_info(verification_tokens)'))
                ->keyBy('name');
            $this->assertSame('TIMESTAMP', $columns['expires_at']->type);

            $tableDefinition = DB::selectOne(
                "SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'verification_tokens'",
            )->sql;
            $this->assertStringContainsString(
                "CHECK (type IN ('email_verify', 'mobile_otp', 'password_reset', 'account_unlock'))",
                $tableDefinition,
            );
        }
    }

    public function test_sqlite_verification_token_type_only_accepts_defined_values(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('SQLite enum constraints require SQLite.');
        }

        $user = User::factory()->create();
        foreach (['email_verify', 'mobile_otp', 'password_reset', 'account_unlock'] as $type) {
            DB::table('verification_tokens')->insert([
                'id' => (string) Str::uuid(),
                'user_id' => $user->id,
                'token_hash' => hash('sha256', $type),
                'type' => $type,
                'expires_at' => now()->addMinutes(5),
            ]);
        }

        $this->assertSame(4, DB::table('verification_tokens')->where('user_id', $user->id)->count());
        $this->expectException(QueryException::class);

        DB::table('verification_tokens')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'token_hash' => hash('sha256', 'invalid'),
            'type' => 'invalid',
            'expires_at' => now()->addMinutes(5),
        ]);
    }

    public function test_postgresql_schema_matches_the_specification(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL information_schema assertions require PostgreSQL.');
        }

        $columns = DB::select(
            "SELECT table_name, column_name, data_type, character_maximum_length, is_nullable, column_default
             FROM information_schema.columns
             WHERE table_schema = current_schema()
               AND table_name IN ('users', 'addresses', 'verification_tokens')
             ORDER BY CASE table_name
                 WHEN 'users' THEN 1
                 WHEN 'addresses' THEN 2
                 ELSE 3
             END, ordinal_position",
        );

        $actualColumns = array_map(
            fn (object $column): array => [
                $column->table_name,
                $column->column_name,
                $column->data_type,
                $column->character_maximum_length === null ? null : (int) $column->character_maximum_length,
                $column->is_nullable,
                $column->column_default,
            ],
            $columns,
        );

        $expectedColumnDefinitions = [
            'users' => [
                ['id', 'uuid', null, 'NO', null],
                ['first_name', 'character varying', 50, 'NO', null],
                ['last_name', 'character varying', 50, 'NO', null],
                ['middle_initial', 'character varying', 2, 'YES', null],
                ['birthday', 'date', null, 'NO', null],
                ['email', 'character varying', 255, 'NO', null],
                ['email_verified_at', 'timestamp without time zone', null, 'YES', null],
                ['password_hash', 'character varying', 255, 'NO', null],
                ['mobile_number', 'character varying', 20, 'NO', null],
                ['mobile_verified', 'boolean', null, 'NO', 'false'],
                ['failed_login_attempts', 'integer', null, 'NO', '0'],
                ['is_locked', 'boolean', null, 'NO', 'false'],
                ['lockout_until', 'timestamp without time zone', null, 'YES', null],
                ['created_at', 'timestamp without time zone', null, 'YES', null],
                ['updated_at', 'timestamp without time zone', null, 'YES', null],
            ],
            'addresses' => [
                ['id', 'uuid', null, 'NO', null],
                ['user_id', 'uuid', null, 'NO', null],
                ['house_street', 'character varying', 255, 'NO', null],
                ['country', 'character varying', 100, 'NO', null],
                ['city', 'character varying', 100, 'NO', null],
                ['state', 'character varying', 100, 'NO', null],
                ['zip_code', 'character varying', 20, 'NO', null],
            ],
            'verification_tokens' => [
                ['id', 'uuid', null, 'NO', null],
                ['user_id', 'uuid', null, 'NO', null],
                ['token_hash', 'character varying', 255, 'NO', null],
                ['type', 'USER-DEFINED', null, 'NO', null],
                ['expires_at', 'timestamp without time zone', null, 'NO', null],
            ],
        ];
        $expectedColumns = [];
        foreach ($expectedColumnDefinitions as $table => $definitions) {
            foreach ($definitions as [$column, $type, $length, $nullable, $default]) {
                $expectedColumns[] = [$table, $column, $type, $length, $nullable, $default];
            }
        }

        $this->assertSame($expectedColumns, $actualColumns);

        $constraints = DB::select(
            "SELECT table_name, constraint_name, constraint_type
             FROM information_schema.table_constraints
             WHERE table_schema = current_schema()
               AND table_name IN ('users', 'addresses', 'verification_tokens')
             ORDER BY table_name,
                 CASE constraint_type
                     WHEN 'PRIMARY KEY' THEN 1
                     WHEN 'FOREIGN KEY' THEN 2
                     WHEN 'UNIQUE' THEN 3
                     ELSE 4
                 END,
                 constraint_name",
        );
        $actualConstraints = array_map(
            fn (object $constraint): array => [
                $constraint->table_name,
                $constraint->constraint_name,
                $constraint->constraint_type,
            ],
            $constraints,
        );
        $this->assertSame([
            ['addresses', 'addresses_pkey', 'PRIMARY KEY'],
            ['addresses', 'addresses_user_id_foreign', 'FOREIGN KEY'],
            ['addresses', 'addresses_user_id_unique', 'UNIQUE'],
            ['users', 'users_pkey', 'PRIMARY KEY'],
            ['users', 'users_email_unique', 'UNIQUE'],
            ['users', 'users_mobile_number_unique', 'UNIQUE'],
            ['verification_tokens', 'verification_tokens_pkey', 'PRIMARY KEY'],
            ['verification_tokens', 'verification_tokens_user_id_foreign', 'FOREIGN KEY'],
            ['verification_tokens', 'verification_tokens_user_id_type_unique', 'UNIQUE'],
        ], $actualConstraints);

        $foreignKeys = DB::select(
            "SELECT tc.table_name, kcu.column_name, ccu.table_name AS foreign_table_name,
                    ccu.column_name AS foreign_column_name, rc.delete_rule
             FROM information_schema.table_constraints tc
             JOIN information_schema.key_column_usage kcu
               ON tc.constraint_name = kcu.constraint_name
              AND tc.constraint_schema = kcu.constraint_schema
             JOIN information_schema.constraint_column_usage ccu
               ON ccu.constraint_name = tc.constraint_name
              AND ccu.constraint_schema = tc.constraint_schema
             JOIN information_schema.referential_constraints rc
               ON rc.constraint_name = tc.constraint_name
              AND rc.constraint_schema = tc.constraint_schema
             WHERE tc.constraint_schema = current_schema()
               AND tc.constraint_type = 'FOREIGN KEY'
               AND tc.table_name IN ('addresses', 'verification_tokens')
             ORDER BY tc.table_name",
        );
        $this->assertSame([
            ['addresses', 'user_id', 'users', 'id', 'CASCADE'],
            ['verification_tokens', 'user_id', 'users', 'id', 'CASCADE'],
        ], array_map(
            fn (object $foreignKey): array => [
                $foreignKey->table_name,
                $foreignKey->column_name,
                $foreignKey->foreign_table_name,
                $foreignKey->foreign_column_name,
                $foreignKey->delete_rule,
            ],
            $foreignKeys,
        ));

        $emailIndexes = DB::select(
            "SELECT indexname, indexdef FROM pg_indexes
             WHERE schemaname = current_schema()
               AND tablename = 'users'
               AND indexdef LIKE '%(email)%'",
        );
        $this->assertCount(1, $emailIndexes);
        $this->assertStringContainsString('UNIQUE INDEX', strtoupper($emailIndexes[0]->indexdef));
        $this->assertStringContainsString('(email)', $emailIndexes[0]->indexdef);

        $enumValues = DB::select(
            "SELECT enumlabel
             FROM pg_enum
             JOIN pg_type ON pg_enum.enumtypid = pg_type.oid
             JOIN pg_namespace ON pg_type.typnamespace = pg_namespace.oid
             WHERE pg_type.typname = 'verification_token_type'
               AND pg_namespace.nspname = current_schema()
             ORDER BY enumsortorder",
        );
        $this->assertSame(
            ['email_verify', 'mobile_otp', 'password_reset', 'account_unlock'],
            array_column($enumValues, 'enumlabel'),
        );
    }

    public function test_production_rejects_http_requests(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');

        $this->withServerVariables([
            'REMOTE_ADDR' => '192.0.2.10',
            'SERVER_PORT' => '80',
            'HTTPS' => 'off',
            'HTTP_X_FORWARDED_PROTO' => 'http',
        ])->getJson('http://localhost/api/holidays/2026')->assertStatus(426);

        $this->withServerVariables([
            'REMOTE_ADDR' => '127.0.0.1',
            'SERVER_PORT' => '80',
            'HTTPS' => 'off',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ])->getJson('http://localhost/csrf-token')
            ->assertOk()
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000');
    }

    public function test_registration_rejects_a_mobile_number_for_the_wrong_country(): void
    {
        $this->postJson('/api/register', [
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'birthday' => '04/17/1995',
            'email' => 'maria.santos@gmail.com',
            'mobile_number' => '+14085551212',
            'country' => 'Philippines',
            'country_code' => 'PH',
            'house_street' => '12 Sampaguita Street',
            'city' => 'Quezon City',
            'state' => 'Metro Manila',
            'zip_code' => '1100',
            'password' => 'SafePassword!482',
            'password_confirmation' => 'SafePassword!482',
        ])->assertUnprocessable()->assertJsonValidationErrors('mobile_number');
    }

    public function test_registration_stores_the_canonical_country_for_the_country_code(): void
    {
        Notification::fake();

        $this->postJson('/api/register', [
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'birthday' => '04/17/1995',
            'email' => 'maria.santos@gmail.com',
            'mobile_number' => '+6591234567',
            'country' => 'Philippines',
            'country_code' => 'SG',
            'house_street' => '12 Sampaguita Street',
            'city' => 'Singapore',
            'state' => 'Singapore',
            'zip_code' => '018956',
            'password' => 'SafePassword!482',
            'password_confirmation' => 'SafePassword!482',
        ])->assertCreated();

        $this->assertDatabaseHas('addresses', [
            'country' => 'Singapore',
        ]);
    }

    public function test_registration_rejects_a_postal_code_for_the_wrong_country(): void
    {
        $this->postJson('/api/register', [
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'birthday' => '04/17/1995',
            'email' => 'maria.santos@gmail.com',
            'mobile_number' => '+6591234567',
            'country' => 'Singapore',
            'country_code' => 'SG',
            'house_street' => '12 Sampaguita Street',
            'city' => 'Singapore',
            'state' => 'Singapore',
            'zip_code' => '12345',
            'password' => 'SafePassword!482',
            'password_confirmation' => 'SafePassword!482',
        ])->assertUnprocessable()->assertJsonValidationErrors('zip_code');
    }

    public function test_postal_code_format_endpoint_returns_the_selected_country_format(): void
    {
        $this->getJson('/api/postal-code-format/JP')
            ->assertOk()
            ->assertExactJson([
                'country_code' => 'JP',
                'pattern' => '\d{3}-?\d{4}',
            ]);

        $this->getJson('/api/postal-code-format/XX')->assertNotFound();
    }

    public function test_registration_accepts_countries_outside_the_previous_short_list(): void
    {
        Notification::fake();

        $response = $this->postJson('/api/register', [
            'first_name' => 'Yuki',
            'last_name' => 'Tanaka',
            'birthday' => '04/17/1995',
            'email' => 'yuki.tanaka@gmail.com',
            'mobile_number' => '9012345678',
            'country' => 'Japan',
            'country_code' => 'JP',
            'house_street' => '1 Chome Marunouchi',
            'city' => 'Tokyo',
            'state' => 'Tokyo',
            'zip_code' => ' 100-0001 ',
            'password' => 'SafePassword!482',
            'password_confirmation' => 'SafePassword!482',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('addresses', [
            'country' => 'Japan',
            'city' => 'Tokyo',
            'state' => 'Tokyo',
            'zip_code' => '100-0001',
        ]);
    }

    public function test_locked_account_requires_unlock_email_link_after_two_minute_hold(): void
    {
        $this->freezeTime();
        $lockoutMessage = 'Your account has been temporarily locked. An unlock email has been sent to your registered email address. Please check your inbox.';
        $unlockEmailText = null;
        $unlockEmailCallback = null;
        Mail::shouldReceive('raw')->once()->withArgs(function (string $text, \Closure $callback) use (&$unlockEmailText, &$unlockEmailCallback): bool {
            $unlockEmailText = $text;
            $unlockEmailCallback = $callback;

            return true;
        });

        $user = User::forceCreate([
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'birthday' => '1995-04-17',
            'email' => 'maria.santos@gmail.com',
            'email_verified_at' => now(),
            'password_hash' => 'SafePassword!482',
            'mobile_number' => '+639171234567',
            'mobile_verified' => true,
            'mobile_verified' => true,
        ]);

        $unknownAccountResponse = $this->postJson('/api/login', [
            'email' => 'missing.user@gmail.com',
            'password' => 'wrong password',
        ]);
        $unknownAccountResponse->assertUnprocessable()->assertJsonValidationErrors([
            'email' => 'Invalid email or password',
        ]);
        $this->assertStringNotContainsString('Too Many Attempts', $unknownAccountResponse->json('message'));

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $failedLoginResponse = $this->postJson('/api/login', [
                'email' => $user->email,
                'password' => 'wrong password',
            ]);
            $failedLoginResponse->assertUnprocessable()->assertJsonValidationErrors([
                'email' => 'Invalid email or password',
            ]);
            $this->assertStringNotContainsString('Too Many Attempts', $failedLoginResponse->json('message'));
        }

        $thirdFailureResponse = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'wrong password',
        ]);
        $thirdFailureResponse->assertStatus(423)->assertExactJson([
            'message' => $lockoutMessage,
        ]);
        $this->assertStringNotContainsString('Too Many Attempts', $thirdFailureResponse->json('message'));

        $this->assertTrue($user->fresh()->is_locked);
        $this->assertSame(now()->addMinutes(2)->toDateTimeString(), $user->fresh()->lockout_until->toDateTimeString());
        $this->assertStringContainsString('account/unlock/'.$user->id, $unlockEmailText);

        for ($attempt = 0; $attempt < 6; $attempt++) {
            $lockedLoginResponse = $this->postJson('/api/login', [
                'email' => $user->email,
                'password' => $attempt % 2 === 0 ? 'SafePassword!482' : 'wrong password',
            ]);

            $lockedLoginResponse->assertStatus(423)->assertExactJson([
                'message' => $lockoutMessage,
            ]);
            $this->assertStringNotContainsString('Too Many Attempts', $lockedLoginResponse->json('message'));
        }

        $mailMessage = \Mockery::mock();
        $mailMessage->shouldReceive('to')->once()->with($user->email)->andReturnSelf();
        $mailMessage->shouldReceive('subject')->once()->with('Daymark account security alert')->andReturnSelf();
        $unlockEmailCallback($mailMessage);

        preg_match('/https?:\/\/[^\s]+/', $unlockEmailText, $unlockUrlMatches);
        $this->assertCount(1, $unlockUrlMatches);
        $this->get($unlockUrlMatches[0])->assertRedirect();
        $this->assertTrue($user->fresh()->is_locked);

        $this->travel(2)->minutes();

        $this->get($unlockUrlMatches[0])->assertRedirect();
        $this->assertFalse($user->fresh()->is_locked);

        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'SafePassword!482',
        ])->assertOk();
        $this->assertFalse($user->fresh()->is_locked);
        $this->assertSame(0, $user->fresh()->failed_login_attempts);
    }

    public function test_locked_account_resends_unlock_email_after_two_minutes_with_24_hour_expiration(): void
    {
        $this->freezeTime();
        $unlockEmails = [];
        Mail::shouldReceive('raw')->twice()->withArgs(function (string $text, \Closure $callback) use (&$unlockEmails): bool {
            $unlockEmails[] = ['text' => $text, 'callback' => $callback];

            return true;
        });

        $user = User::forceCreate([
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'birthday' => '1995-04-17',
            'email' => 'maria.santos@gmail.com',
            'email_verified_at' => now(),
            'password_hash' => 'SafePassword!482',
            'mobile_number' => '+639171234567',
            'mobile_verified' => true,
        ]);

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $response = $this->postJson('/api/login', [
                'email' => $user->email,
                'password' => 'wrong password',
            ]);
            $response->assertStatus($attempt === 2 ? 423 : 422);
        }

        $this->assertCount(1, $unlockEmails);
        $firstExpiry = now()->addHours(24);
        $this->assertSame(
            $firstExpiry->toDateTimeString(),
            Carbon::parse(DB::table('verification_tokens')->where('user_id', $user->id)->where('type', 'account_unlock')->value('expires_at'))->toDateTimeString(),
        );

        $this->travel(1)->minutes();
        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'SafePassword!482',
        ])->assertStatus(423);
        $this->assertCount(1, $unlockEmails);
        $initialLockoutUntil = $user->fresh()->lockout_until->copy();

        $this->travel(1)->minutes();
        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'SafePassword!482',
        ])->assertStatus(423);
        $this->assertCount(2, $unlockEmails);
        $resendAt = now();
        $this->assertSame($initialLockoutUntil->toDateTimeString(), $user->fresh()->lockout_until->toDateTimeString());

        foreach ($unlockEmails as $email) {
            $mailMessage = \Mockery::mock();
            $mailMessage->shouldReceive('to')->once()->with($user->email)->andReturnSelf();
            $mailMessage->shouldReceive('subject')->once()->with('Daymark account security alert')->andReturnSelf();
            $email['callback']($mailMessage);
        }

        preg_match('/https?:\/\/[^\s]+/', $unlockEmails[1]['text'], $unlockUrlMatches);
        $this->assertCount(1, $unlockUrlMatches);
        parse_str((string) parse_url($unlockUrlMatches[0], PHP_URL_QUERY), $query);
        $this->assertSame((string) $resendAt->copy()->addHours(24)->timestamp, $query['expires']);
        $this->assertSame(
            $resendAt->copy()->addHours(24)->toDateTimeString(),
            Carbon::parse(DB::table('verification_tokens')->where('user_id', $user->id)->where('type', 'account_unlock')->value('expires_at'))->toDateTimeString(),
        );

        $this->travel(1)->minutes();
        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'SafePassword!482',
        ])->assertStatus(423);
        $this->assertCount(2, $unlockEmails);

        $this->travelTo($resendAt->copy()->addHours(24));
        $this->get($unlockUrlMatches[0])->assertForbidden();
    }

    public function test_failed_unlock_email_delivery_is_reported_instead_of_claiming_it_was_sent(): void
    {
        Mail::shouldReceive('raw')->once()->andThrow(new \RuntimeException('SMTP unavailable'));

        $user = User::forceCreate([
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'birthday' => '1995-04-17',
            'email' => 'maria.santos@gmail.com',
            'email_verified_at' => now(),
            'password_hash' => 'SafePassword!482',
            'mobile_number' => '+639171234567',
            'mobile_verified' => true,
        ]);

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->postJson('/api/login', [
                'email' => $user->email,
                'password' => 'wrong password',
            ])->assertUnprocessable();
        }

        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'wrong password',
        ]);

        $response->assertStatus(503)->assertExactJson([
            'message' => 'Your account has been locked, but the unlock email could not be sent. Please contact support.',
        ]);
        $this->assertDatabaseMissing('verification_tokens', [
            'user_id' => $user->id,
            'type' => 'account_unlock',
        ]);
    }

    public function test_login_authenticates_with_a_cookie_session_that_logout_invalidates(): void
    {
        $user = User::forceCreate([
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'birthday' => '1995-04-17',
            'email' => 'maria.santos@gmail.com',
            'email_verified_at' => now(),
            'password_hash' => 'SafePassword!482',
            'mobile_number' => '+639171234567',
            'mobile_verified' => true,
            'failed_login_attempts' => 2,
        ]);

        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'SafePassword!482',
        ])->assertOk();

        $this->assertSame($user->id, $response->json('user.id'));
        $this->assertSame(0, $user->fresh()->failed_login_attempts);
        $this->assertFalse(Schema::hasTable('api_tokens'));

        $this->getJson('/api/me')->assertOk()->assertJsonPath('email', $user->email);
        $this->postJson('/api/logout')->assertOk();
        $this->getJson('/api/me')->assertUnauthorized();
    }

    public function test_logout_succeeds_without_an_authenticated_session(): void
    {
        $this->postJson('/api/logout')->assertOk();
        $this->getJson('/api/me')->assertUnauthorized();
    }

    public function test_accounts_list_requires_authentication_and_returns_only_display_fields(): void
    {
        $this->getJson('/api/accounts')->assertUnauthorized();

        User::forceCreate([
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'birthday' => '1995-04-17',
            'email' => 'maria.santos@gmail.com',
            'email_verified_at' => now(),
            'password_hash' => 'SafePassword!482',
            'mobile_number' => '+639171234567',
            'mobile_verified' => true,
        ]);
        User::forceCreate([
            'first_name' => 'Ana',
            'last_name' => 'Cruz',
            'birthday' => '1994-03-12',
            'email' => 'ana.cruz@gmail.com',
            'email_verified_at' => null,
            'password_hash' => 'SafePassword!482',
            'mobile_number' => '+639181234567',
        ]);
        User::forceCreate([
            'first_name' => 'Liza',
            'last_name' => 'Reyes',
            'birthday' => '1993-02-08',
            'email' => 'liza.reyes@gmail.com',
            'email_verified_at' => now(),
            'password_hash' => 'SafePassword!482',
            'mobile_number' => '+639191234567',
            'mobile_verified' => false,
        ]);

        $this->postJson('/api/login', [
            'email' => 'maria.santos@gmail.com',
            'password' => 'SafePassword!482',
        ])->assertOk();

        $this->getJson('/api/accounts')
            ->assertOk()
            ->assertExactJson([
                'accounts' => [
                    [
                        'name' => 'Ana Cruz',
                        'email' => 'ana.cruz@gmail.com',
                        'email_verified' => false,
                    ],
                    [
                        'name' => 'Liza Reyes',
                        'email' => 'liza.reyes@gmail.com',
                        'email_verified' => false,
                    ],
                    [
                        'name' => 'Maria Santos',
                        'email' => 'maria.santos@gmail.com',
                        'email_verified' => true,
                    ],
                ],
            ]);
    }

    public function test_valid_mobile_code_verifies_the_number_and_signs_in(): void
    {
        $user = User::forceCreate([
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'birthday' => '1995-04-17',
            'email' => 'maria.santos@gmail.com',
            'email_verified_at' => now(),
            'password_hash' => 'SafePassword!482',
            'mobile_number' => '+639171234567',
        ]);
        $code = '731942';
        $now = now();

        DB::table('verification_tokens')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'token_hash' => hash_hmac('sha256', $code, config('app.key')),
            'type' => 'mobile_otp',
            'expires_at' => $now->copy()->addMinutes(5),
        ]);

        $response = $this->postJson('/api/mobile/verify', [
            'email' => $user->email,
            'password' => 'SafePassword!482',
            'code' => $code,
        ])->assertOk();

        $this->assertTrue($user->fresh()->mobile_verified);
        $this->assertSame($user->id, $response->json('user.id'));
        $this->getJson('/api/me')->assertOk()->assertJsonPath('email', $user->email);
        $this->assertDatabaseMissing('verification_tokens', [
            'user_id' => $user->id,
            'type' => 'mobile_otp',
        ]);
    }

    public function test_mobile_otp_and_account_unlock_tokens_coexist_by_type(): void
    {
        $this->freezeTime();

        $user = User::forceCreate([
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'birthday' => '1995-04-17',
            'email' => 'maria.token-types@gmail.com',
            'email_verified_at' => now(),
            'password_hash' => 'SafePassword!482',
            'mobile_number' => '+639171234567',
        ]);
        $code = '731942';
        $expiresAt = now()->addMinutes(5);

        DB::table('verification_tokens')->insert([
            [
                'id' => (string) Str::uuid(),
                'user_id' => $user->id,
                'token_hash' => hash_hmac('sha256', 'unlock-token', config('app.key')),
                'type' => 'account_unlock',
                'expires_at' => now()->addMinutes(15),
            ],
            [
                'id' => (string) Str::uuid(),
                'user_id' => $user->id,
                'token_hash' => hash_hmac('sha256', $code, config('app.key')),
                'type' => 'mobile_otp',
                'expires_at' => $expiresAt,
            ],
        ]);

        $this->assertDatabaseCount('verification_tokens', 2);
        $this->postJson('/api/mobile/verify', [
            'email' => $user->email,
            'password' => 'SafePassword!482',
            'code' => $code,
        ])->assertOk();

        $this->assertDatabaseMissing('verification_tokens', [
            'user_id' => $user->id,
            'type' => 'mobile_otp',
        ]);
    }

    public function test_mobile_code_is_expired_at_the_five_minute_boundary(): void
    {
        $this->freezeTime();

        $user = User::forceCreate([
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'birthday' => '1995-04-17',
            'email' => 'maria.santos@gmail.com',
            'email_verified_at' => now(),
            'password_hash' => 'SafePassword!482',
            'mobile_number' => '+639171234567',
        ]);
        $code = '731942';
        $now = now();

        DB::table('verification_tokens')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'token_hash' => hash_hmac('sha256', $code, config('app.key')),
            'type' => 'mobile_otp',
            'expires_at' => $now->copy()->addMinutes(5),
        ]);

        $this->travel(5)->minutes();

        $this->postJson('/api/mobile/verify', [
            'email' => $user->email,
            'password' => 'SafePassword!482',
            'code' => $code,
        ])->assertUnprocessable()->assertJsonValidationErrors('code');
    }

    public function test_three_incorrect_mobile_codes_lock_verification(): void
    {
        $this->freezeTime();
        Mail::fake();
        config([
            'services.textbee.api_key' => 'test-api-key',
            'services.textbee.gateway_id' => 'test-gateway',
        ]);
        Http::preventStrayRequests();
        Http::fake(['api.textbee.dev/api/v1/gateway/send-sms' => Http::response(['success' => true], 200)]);

        $user = User::forceCreate([
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'birthday' => '1995-04-17',
            'email' => 'maria.santos@gmail.com',
            'email_verified_at' => now(),
            'password_hash' => 'SafePassword!482',
            'mobile_number' => '+639171234567',
        ]);
        $user->address()->create([
            'house_street' => '12 Sampaguita Street',
            'country' => 'Philippines',
            'city' => 'Quezon City',
            'state' => 'Metro Manila',
            'zip_code' => '1100',
        ]);
        $now = now();

        DB::table('verification_tokens')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'token_hash' => hash_hmac('sha256', '731942', config('app.key')),
            'type' => 'mobile_otp',
            'expires_at' => $now->copy()->addMinutes(5),
        ]);

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->postJson('/api/mobile/verify', [
                'email' => $user->email,
                'password' => 'SafePassword!482',
                'code' => '000000',
            ])->assertUnprocessable()->assertJsonValidationErrors('code');
        }

        $this->travel(60)->seconds();

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.52'])->postJson('/api/mobile/resend', [
            'email' => $user->email,
            'password' => 'SafePassword!482',
        ])->assertOk();

        $this->assertSame(2, app(LocalJsonStore::class)->read()['otp'][$user->id]['attempts']);

        $this->postJson('/api/mobile/verify', [
            'email' => $user->email,
            'password' => 'SafePassword!482',
            'code' => '000000',
        ])->assertUnprocessable()->assertJsonValidationErrors('code');

        $this->assertDatabaseHas('verification_tokens', [
            'user_id' => $user->id,
            'type' => 'account_unlock',
        ]);
        $this->assertTrue($user->fresh()->is_locked);

        $this->postJson('/api/mobile/verify', [
            'email' => $user->email,
            'password' => 'SafePassword!482',
            'code' => '731942',
        ])->assertStatus(423);

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $lockedVerificationResponse = $this->postJson('/api/mobile/verify', [
                'email' => $user->email,
                'password' => 'SafePassword!482',
                'code' => '731942',
            ]);

            $lockedVerificationResponse->assertStatus(423);
            $this->assertStringNotContainsString('Too Many Attempts', $lockedVerificationResponse->getContent());
        }

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.53'])->postJson('/api/mobile/resend', [
            'email' => $user->email,
            'password' => 'SafePassword!482',
        ])->assertStatus(423);
    }

    public function test_resend_otp_uses_the_application_cooldown_and_resubmitted_code_verifies(): void
    {
        $this->freezeTime();
        config([
            'services.textbee.api_key' => 'test-api-key',
            'services.textbee.gateway_id' => 'test-gateway',
        ]);
        Http::preventStrayRequests();
        Http::fake(['api.textbee.dev/api/v1/gateway/send-sms' => Http::response(['success' => true], 200)]);

        $user = User::forceCreate([
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'birthday' => '1995-04-17',
            'email' => 'maria.santos@gmail.com',
            'email_verified_at' => now(),
            'password_hash' => 'SafePassword!482',
            'mobile_number' => '+639171234567',
        ]);
        $user->address()->create([
            'house_street' => '12 Sampaguita Street',
            'country' => 'Philippines',
            'city' => 'Quezon City',
            'state' => 'Metro Manila',
            'zip_code' => '1100',
        ]);

        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'SafePassword!482',
        ])->assertStatus(403)
            ->assertJsonPath('mobile_verification_required', true)
            ->assertJsonPath('debug_code', null);

        $this->assertDatabaseHas('verification_tokens', [
            'user_id' => $user->id,
            'type' => 'mobile_otp',
        ]);

        $expectedExpiration = now()->addMinutes(5)->setTimezone(new \DateTimeZone('Asia/Manila'))->format('g:i A');
        Http::assertSent(fn (ClientRequest $request) => str_contains($request->url(), 'api.textbee.dev/api/v1/gateway/send-sms')
            && $request->header('x-api-key') === ['test-api-key']
            && $request['deviceId'] === 'test-gateway'
            && $request['recipients'] === [$user->mobile_number]
            && preg_match('/^Your code is [0-9]{6}\. It expires at '.preg_quote($expectedExpiration, '/').' Asia\/Manila\.$/', $request['message']) === 1);

        for ($attempt = 0; $attempt < 6; $attempt++) {
            $earlyResendResponse = $this->postJson('/api/mobile/resend', [
                'email' => $user->email,
                'password' => 'SafePassword!482',
            ]);

            $earlyResendResponse->assertStatus(429);
            $retryAfter = $earlyResendResponse->json('retry_after');
            $this->assertGreaterThan(0, $retryAfter);
            $this->assertLessThanOrEqual(60, $retryAfter);
            $earlyResendResponse->assertJsonPath(
                'message',
                "Please wait {$retryAfter} seconds before requesting another code.",
            );
            $this->assertStringNotContainsString('Too Many Attempts', $earlyResendResponse->getContent());
        }

        $this->travel(60)->seconds();

        $this->postJson('/api/mobile/resend', [
            'email' => $user->email,
            'password' => 'SafePassword!482',
        ])->assertOk()->assertJsonPath('message', 'A new verification code has been sent.');

        $verification = DB::table('verification_tokens')
            ->where('user_id', $user->id)
            ->where('type', 'mobile_otp')
            ->first();
        $this->assertSame(now()->addMinutes(5)->toDateTimeString(), $verification->expires_at);
        $otpState = app(LocalJsonStore::class)->read()['otp'][$user->id];
        $this->assertSame(now()->addSeconds(60)->timestamp, $otpState['resend_until']);
        $this->assertSame(now()->addMinutes(5)->timestamp, $otpState['expires_at']);

        $sentCodes = [];
        Http::assertSent(function (ClientRequest $request) use (&$sentCodes): bool {
            if (! str_contains($request->url(), 'api.textbee.dev/api/v1/gateway/send-sms')
                || preg_match('/^Your code is ([0-9]{6})\. It expires at /', $request['message'], $matches) !== 1) {
                return false;
            }

            if ($request['deviceId'] !== 'test-gateway') {
                return false;
            }

            $sentCodes[] = $matches[1];

            return true;
        });
        $this->assertCount(2, $sentCodes);

        $this->postJson('/api/mobile/verify', [
            'email' => $user->email,
            'password' => 'SafePassword!482',
            'code' => $sentCodes[1],
        ])->assertOk();
        $this->assertTrue($user->fresh()->mobile_verified);
    }

    public function test_mobile_otp_sms_uses_the_first_timezone_for_the_selected_country(): void
    {
        $this->freezeTime();
        config([
            'services.textbee.api_key' => 'test-api-key',
            'services.textbee.gateway_id' => 'test-gateway',
        ]);
        Http::preventStrayRequests();
        Http::fake(['api.textbee.dev/api/v1/gateway/send-sms' => Http::response(['success' => true], 200)]);

        $user = User::forceCreate([
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'birthday' => '1995-04-17',
            'email' => 'maria.santos@gmail.com',
            'email_verified_at' => now(),
            'password_hash' => 'SafePassword!482',
            'mobile_number' => '+4915112345678',
        ]);
        $user->address()->create([
            'house_street' => '1 Hauptstrasse',
            'country' => 'Germany',
            'city' => 'Berlin',
            'state' => 'Berlin',
            'zip_code' => '10115',
        ]);
        $timezone = \DateTimeZone::listIdentifiers(\DateTimeZone::PER_COUNTRY, 'DE')[0];
        $expectedExpiration = now()->addMinutes(5)->setTimezone(new \DateTimeZone($timezone))->format('g:i A');

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.74'])->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'SafePassword!482',
        ])->assertStatus(403)->assertJsonPath('mobile_verification_required', true);

        Http::assertSent(fn (ClientRequest $request) => $request['recipients'] === [$user->mobile_number]
            && preg_match('/^Your code is [0-9]{6}\. It expires at '.preg_quote($expectedExpiration, '/').' '.preg_quote($timezone, '/').'\.$/', $request['message']) === 1);
    }

    private function makeUnlockTestUser(string $email): User
    {
        return User::forceCreate([
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'birthday' => '1995-04-17',
            'email' => $email,
            'email_verified_at' => now(),
            'password_hash' => 'SafePassword!482',
            'mobile_number' => '+639171234567',
            'mobile_verified' => true,
        ]);
    }

    private function issueUnlockUrl(User $user, array $proxy): string
    {
        $unlockEmailText = null;
        $unlockEmailCallback = null;

        Mail::shouldReceive('raw')->once()->withArgs(function (string $text, \Closure $callback) use (&$unlockEmailText, &$unlockEmailCallback): bool {
            $unlockEmailText = $text;
            $unlockEmailCallback = $callback;

            return true;
        });

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $response = $this->withServerVariables($proxy)->postJson('/api/login', [
                'email' => $user->email,
                'password' => 'incorrect password',
            ]);
            $response->assertStatus($attempt === 2 ? 423 : 422);
            $this->assertStringNotContainsString('Too Many Attempts', $response->getContent());
        }

        $this->assertDatabaseHas('verification_tokens', [
            'user_id' => $user->id,
            'type' => 'account_unlock',
        ]);

        $mailMessage = \Mockery::mock();
        $mailMessage->shouldReceive('to')->once()->with($user->email)->andReturnSelf();
        $mailMessage->shouldReceive('subject')->once()->with('Daymark account security alert')->andReturnSelf();
        $unlockEmailCallback($mailMessage);
        preg_match('/https?:\/\/[^\s]+/', $unlockEmailText, $matches);
        $this->assertCount(1, $matches);

        parse_str((string) parse_url($matches[0], PHP_URL_QUERY), $query);
        $verification = DB::table('verification_tokens')
            ->where('user_id', $user->id)
            ->where('type', 'account_unlock')
            ->first();
        $this->assertNotSame($query['token'], $verification->token_hash);
        $this->assertSame(hash_hmac('sha256', $query['token'], config('app.key')), $verification->token_hash);

        return $matches[0];
    }
}
