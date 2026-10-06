<?php

namespace App\Providers;

use App\Categorization\Ai\AiCategorizer;
use App\Categorization\Ai\ClaudeCategorizer;
use App\Categorization\Ai\DemoCategorizer;
use App\Imports\Parsing\CsvParser;
use App\Imports\Parsing\ParserRegistry;
use App\Jobs\Imports\SuggestCategoriesForChunk;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\DevCommands;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ParserRegistry::class, fn ($app) => new ParserRegistry([
            'csv' => $app->make(CsvParser::class),
        ]));

        $this->app->bind(AiCategorizer::class, fn () => match (config('categorization.ai.driver')) {
            'anthropic' => new ClaudeCategorizer(
                model: (string) config('categorization.ai.model'),
                maxTokens: (int) config('categorization.ai.max_tokens'),
                timeoutSeconds: (int) config('categorization.ai.timeout_seconds'),
            ),
            'demo' => new DemoCategorizer,
            default => throw new InvalidArgumentException('AI categorization is disabled or misconfigured (AI_CATEGORIZER).'),
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureDevCommands();
        $this->configureRateLimits();
    }

    /**
     * Shared across every queue worker, so the provider's limits hold however many workers run.
     */
    protected function configureRateLimits(): void
    {
        RateLimiter::for(SuggestCategoriesForChunk::RATE_LIMITER, fn () => Limit::perMinute(
            max(1, (int) config('categorization.ai.requests_per_minute')),
        ));
    }

    /**
     * Run Horizon instead of queue:listen under `composer dev`.
     */
    protected function configureDevCommands(): void
    {
        DevCommands::except('queue');
        DevCommands::artisan('horizon', 'horizon');
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
