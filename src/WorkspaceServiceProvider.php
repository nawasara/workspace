<?php

namespace Nawasara\Workspace;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Symfony\Component\Finder\Finder;

class WorkspaceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/nawasara-workspace.php', 'nawasara-workspace');

        // Built from Vault on each resolve; an empty Vault group must not break
        // page rendering, so the client reports "not configured" instead of
        // throwing at construction (same reasoning as registry's SimasHebatClient).
        $this->app->bind(Services\NextcloudAdminClient::class, fn () => Services\NextcloudAdminClient::fromVault());
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'nawasara-workspace');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        // Guarded: view:cache crashes on a component path that does not exist.
        if (is_dir(__DIR__.'/../resources/views/components')) {
            Blade::anonymousComponentPath(__DIR__.'/../resources/views/components', 'nawasara-workspace');
        }

        $this->registerLivewire();
        $this->registerPilotSync();

        // Scopes before routes: the token UI hides scopes nobody registered,
        // so forgetting this would make the scope impossible to grant.
        $this->registerApiScopes();
        $this->registerApiRoutes();
    }

    /** Guarded by class_exists: the package still works without nawasara/api. */
    public function registerApiScopes(): void
    {
        if (! class_exists(\Nawasara\Api\Support\ScopeRegistry::class)) {
            return;
        }

        $this->app->make(\Nawasara\Api\Support\ScopeRegistry::class)->register(
            'workspace.access.read',
            'Apakah seorang ASN (per NIP) boleh masuk Kisara Workspace saat ini, tahap pilotnya, '
            .'dan apakah ia admin Kisara. Untuk Workspace Web. Read-only, tanpa data pribadi.',
        );
    }

    /** Mount routes/api.php at /api/v1/workspace. */
    public function registerApiRoutes(): void
    {
        if (! class_exists(\Nawasara\Api\ApiServiceProvider::class)) {
            return;
        }

        \Illuminate\Support\Facades\Route::prefix((string) config('nawasara-api.route.prefix', 'api/v1').'/workspace')
            ->middleware(['api', 'api.auth', 'api.log'])
            ->name('nawasara-api.workspace.')
            ->group(__DIR__.'/../routes/api.php');
    }

    /**
     * Keep the Keycloak group `workspace-pilot` in step with the pilot list:
     * right after a change, and every 15 minutes as a safety net. Scheduled
     * with $schedule->call(), not ->command() (AGENTS §7).
     */
    protected function registerPilotSync(): void
    {
        $dispatch = function () {
            if (config('nawasara-workspace.sync_on_change', true)) {
                Jobs\SyncPilotGroupJob::dispatch(triggerSource: 'event')->afterCommit();
            }
        };
        Models\PilotMember::saved($dispatch);
        Models\PilotMember::deleted($dispatch);
        Models\Setting::saved($dispatch);

        // A quota exception changed: run the account sync now (it queues the
        // folder sync), instead of waiting up to sync_interval minutes. Only
        // the changed account is written, thanks to state_hash.
        $syncQuota = function () {
            if (config('nawasara-workspace.sync_on_change', true)) {
                Jobs\SyncWorkspaceAccountsJob::dispatch(triggerSource: 'event')->afterCommit();
            }
        };
        Models\QuotaOverride::saved($syncQuota);
        Models\QuotaOverride::deleted($syncQuota);

        $this->app->booted(function () {
            if (! $this->app->runningInConsole() || ! config('nawasara-workspace.scheduler.enabled', true)) {
                return;
            }

            $this->app->make(\Illuminate\Console\Scheduling\Schedule::class)
                ->call(fn () => Jobs\SyncPilotGroupJob::dispatch(triggerSource: 'scheduled'))
                ->name('nawasara-workspace:sync-pilot-group')
                ->everyFifteenMinutes()
                ->withoutOverlapping(10);

            // Accounts for every ASN (T-10); queues the OPD folder sync (T-12).
            $every = max(5, (int) config('nawasara-workspace.sync_interval', 15));
            $this->app->make(\Illuminate\Console\Scheduling\Schedule::class)
                ->call(fn () => Jobs\SyncWorkspaceAccountsJob::dispatch(triggerSource: 'scheduled'))
                ->name('nawasara-workspace:sync-accounts')
                ->cron("*/{$every} * * * *")
                ->withoutOverlapping(30);
        });
    }

    public function registerLivewire(): void
    {
        $namespace = 'Nawasara\\Workspace\\Livewire';
        $basePath = __DIR__.'/Livewire';

        if (! is_dir($basePath)) {
            return;
        }

        $finder = new Finder();
        $finder->files()->in($basePath)->name('*.php');

        foreach ($finder as $file) {
            $relativePath = str_replace('/', '\\', $file->getRelativePathname());
            $class = $namespace.'\\'.Str::beforeLast($relativePath, '.php');

            if (class_exists($class)) {
                $alias = 'nawasara-workspace.'.
                    Str::of($relativePath)
                        ->replace('.php', '')
                        ->replace('\\', '.')
                        ->replace('/', '.')
                        ->explode('.')
                        ->map(fn ($segment) => Str::kebab($segment))
                        ->join('.');

                Livewire::component($alias, $class);
            }
        }
    }
}
