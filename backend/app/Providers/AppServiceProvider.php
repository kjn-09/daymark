<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        VerifyEmail::createUrlUsing(function (User $user): string {
            $url = clone URL::getFacadeRoot();
            $url->forceRootUrl(config('app.url'));

            return $url->temporarySignedRoute(
                'verification.verify',
                now()->addHours(24),
                ['id' => $user->getKey(), 'hash' => sha1($user->getEmailForVerification())],
            );
        });

        VerifyEmail::toMailUsing(fn (User $user, string $url): MailMessage => (new MailMessage)
            ->subject('Action Required: Verify your email address for '.config('app.name'))
            ->greeting('Dear '.$user->first_name.',')
            ->line('Thank you for registering with '.config('app.name').'. We are thrilled to welcome you to our community.')
            ->line('To ensure the security of your account and complete your registration, please verify your email address by clicking the secure link below:')
            ->action('Verify My Email Address', $url)
            ->line('If you did not initiate this request, please disregard this message. This link will expire in 24 hours for your protection.')
            ->salutation('Warm regards, The '.config('app.name').' Security Team'));

        if (app()->isProduction()) {
            URL::forceScheme('https');
        }
    }
}
