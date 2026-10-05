<?php

namespace Tests\Unit;

use App\Support\LocalJsonStore;
use Tests\TestCase;

class LocalJsonStoreTest extends TestCase
{
    public function test_a_missing_or_corrupt_store_is_treated_as_empty(): void
    {
        $path = storage_path('framework/testing/application-state.json');
        $store = app(LocalJsonStore::class);

        $this->assertSame(['registration' => [], 'otp' => []], $store->read());

        file_put_contents($path, '{invalid json');

        $this->assertSame(['registration' => [], 'otp' => []], $store->read());
        $this->assertSame(['registration' => [], 'otp' => []], json_decode(file_get_contents($path), true));
    }

    public function test_expired_entries_are_dropped_from_the_json_store(): void
    {
        $store = app(LocalJsonStore::class);
        $store->update(function (array &$state): void {
            $state['registration']['expired-ip'] = ['count' => 5, 'expires_at' => now()->subSecond()->timestamp];
            $state['registration']['active-ip'] = ['count' => 2, 'expires_at' => now()->addHour()->timestamp];
            $state['otp']['expired-user'] = ['attempts' => 3, 'expires_at' => now()->subSecond()->timestamp];
        });

        $state = $store->read();

        $this->assertArrayNotHasKey('expired-ip', $state['registration']);
        $this->assertArrayNotHasKey('expired-user', $state['otp']);
        $this->assertSame(2, $state['registration']['active-ip']['count']);
    }
}
