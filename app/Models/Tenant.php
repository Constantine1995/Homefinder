<?php

namespace App\Models;

use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Context;

/**
 * A developer or new-build agency using the product; owns all client data.
 */
#[Fillable(['name'])]
class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory;

    /**
     * The context key holding the ID of the tenant the current request or job works for.
     *
     * Context is passed on to queued jobs, so jobs dispatched for a tenant run for that tenant too.
     */
    public const string CONTEXT_KEY = 'tenant_id';

    /**
     * Get the ID of the tenant the current request or job works for.
     */
    public static function currentId(): ?int
    {
        return Context::get(self::CONTEXT_KEY);
    }

    /**
     * Make the tenant current for the rest of the request or job.
     */
    public function makeCurrent(): static
    {
        Context::add(self::CONTEXT_KEY, $this->getKey());

        return $this;
    }

    /**
     * Run the callback with the tenant made current, then restore the previous current tenant.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function run(callable $callback): mixed
    {
        return Context::scope($callback, [self::CONTEXT_KEY => $this->getKey()]);
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * @return HasMany<FeedSource, $this>
     */
    public function feedSources(): HasMany
    {
        return $this->hasMany(FeedSource::class);
    }

    /**
     * @return HasMany<Complex, $this>
     */
    public function complexes(): HasMany
    {
        return $this->hasMany(Complex::class);
    }
}
