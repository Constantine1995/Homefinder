<?php

namespace App\Models;

use App\Enums\ChannelType;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\ChannelFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A messenger bot or site widget of the developer, which buyers write to.
 *
 * The credentials (the bot token, the webhook secret) are stored encrypted. A widget has none: its external ID is
 * the public key the developer's site embeds it with.
 */
#[Fillable(['type', 'name', 'external_id', 'credentials', 'settings', 'is_active'])]
#[Hidden(['credentials'])]
class Channel extends Model
{
    /** @use HasFactory<ChannelFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ChannelType::class,
            'credentials' => 'encrypted:array',
            'settings' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Contact, $this>
     */
    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }

    /**
     * Get the token the channel's platform gave the developer's bot.
     */
    public function token(): string
    {
        return $this->credentials['token'];
    }

    /**
     * Get the secret the platform sends with every call of the channel's webhook.
     */
    public function webhookSecret(): string
    {
        return $this->credentials['webhook_secret'];
    }

    /**
     * Get the link to the developer's privacy policy the buyer agrees to before the dialog.
     */
    public function policyUrl(): string
    {
        return $this->settings['policy_url'];
    }

    /**
     * Get what the AI assistant says of itself before asking the buyer's consent.
     */
    public function introduction(): string
    {
        return "Здравствуйте! Я ИИ-помощник отдела продаж «{$this->tenant->name}»: подберу квартиру, посчитаю ипотеку, "
            .'отвечу на вопросы о ЖК и помогу записаться на просмотр.';
    }
}
