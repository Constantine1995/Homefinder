<?php

namespace App\Models;

use App\Enums\ChannelType;
use App\Enums\ConversationStatus;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\ContactFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A buyer writing to the developer through a channel.
 *
 * A visitor of the site widget is known by a random token the widget keeps in the browser; the database holds only
 * its hash, as the channel user ID.
 */
#[Fillable(['channel_id', 'channel_user_id', 'name', 'username', 'phone', 'consent_at'])]
class Contact extends Model
{
    /** @use HasFactory<ContactFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'consent_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Channel, $this>
     */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    /**
     * @return HasMany<Conversation, $this>
     */
    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    /**
     * Whether the buyer agreed to the processing of personal data; until then the agent does not answer.
     */
    public function hasConsented(): bool
    {
        return $this->consent_at !== null;
    }

    /**
     * Get the buyer's open conversation, or start a new one once the last is closed.
     */
    public function openConversation(): Conversation
    {
        return $this->conversations()->whereNot('status', ConversationStatus::Closed)->latest('id')->first()
            ?? $this->conversations()->create(['channel' => $this->channel->type->value]);
    }

    /**
     * Start a visitor of the site widget and get its token, returned once, for the widget to send with every request.
     */
    public static function startWidgetVisitor(Channel $channel): string
    {
        $token = Str::random(48);
        $channel->tenant->run(fn (): Contact => $channel->contacts()->create(['channel_user_id' => self::widgetUserId($token)]));

        return $token;
    }

    /**
     * Find the visitor of an active site widget by the token the widget sent.
     */
    public static function findByWidgetToken(?string $token): ?Contact
    {
        if ($token === null || $token === '') {
            return null;
        }

        return static::query()
            ->where('channel_user_id', self::widgetUserId($token))
            ->whereHas('channel', fn (Builder $channel) => $channel->where('type', ChannelType::Widget)->where('is_active', true))
            ->first();
    }

    private static function widgetUserId(string $token): string
    {
        return hash('sha256', $token);
    }
}
