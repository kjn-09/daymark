<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    $frontend = public_path('ui/index.html');

    abort_unless(is_file($frontend), 503, 'Frontend build is missing. Run npm run build from the frontend directory.');

    return response()->file($frontend);
});

Route::get('/csrf-token', fn () => response()->json(['token' => csrf_token()]));

Route::get('/email/verify/{id}/{hash}', function (Request $request, string $id, string $hash) {
    $user = User::findOrFail($id);

    abort_unless(hash_equals(sha1($user->getEmailForVerification()), $hash), 403);

    if (! $user->hasVerifiedEmail()) {
        $user->markEmailAsVerified();
    }

    return redirect($request->getSchemeAndHttpHost().'/?verified=1');
})->middleware('signed')->name('verification.verify');

Route::get('/account/unlock/{id}', function (Request $request, string $id) {
    $user = User::findOrFail($id);
    $frontend = $request->getSchemeAndHttpHost();
    $token = $request->query('token');
    $verification = DB::table('verification_tokens')
        ->where('user_id', $user->id)
        ->where('type', 'account_unlock')
        ->first();

    abort_unless(
        is_string($token)
        && $verification
        && ! now()->greaterThanOrEqualTo($verification->expires_at)
        && hash_equals($verification->token_hash, hash_hmac('sha256', $token, config('app.key'))),
        403,
    );

    if ($user->lockout_until && $user->lockout_until->isFuture()) {
        return redirect($frontend.'/?unlock_wait=1');
    }

    $user->forceFill([
        'failed_login_attempts' => 0,
        'is_locked' => false,
        'lockout_until' => null,
    ])->save();
    DB::table('verification_tokens')->where('id', $verification->id)->delete();

    return redirect($frontend.'/?unlocked=1');
})->middleware('signed')->name('account.unlock');
