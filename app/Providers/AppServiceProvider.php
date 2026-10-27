<?php

namespace App\Providers;

use App\Models\Contact;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Messages and card buttons a visitor of the site widget may send in an hour, as a buyer may in Telegram.
     */
    public const int WIDGET_MESSAGES_PER_HOUR = 30;

    /**
     * Visitors one address may start in an hour: a browser starts one and keeps it.
     */
    public const int WIDGET_VISITORS_PER_HOUR = 20;

    public const string TOO_MANY_MESSAGES = 'Сообщений слишком много — я отвечу чуть позже. Если вопрос срочный, попросите менеджера.';

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
        Model::shouldBeStrict(! $this->app->isProduction());

        Gate::define('viewStatistics', fn (User $user): bool => $user->role->seesStatistics());

        Auth::viaRequest('widget-token', fn (Request $request): ?Contact => Contact::findByWidgetToken($request->bearerToken()));

        RateLimiter::for('widget-messages', fn (Request $request): Limit => Limit::perHour(self::WIDGET_MESSAGES_PER_HOUR)
            ->by('widget-contact:'.$request->user()->id)
            ->response(fn () => response()->json(['message' => self::TOO_MANY_MESSAGES], 429)));

        RateLimiter::for('widget-visitors', fn (Request $request): Limit => Limit::perHour(self::WIDGET_VISITORS_PER_HOUR)->by('widget-visitors:'.$request->ip()));
    }
}
