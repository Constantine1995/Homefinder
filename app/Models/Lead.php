<?php

namespace App\Models;

use App\Enums\LeadPayment;
use App\Enums\LeadScore;
use App\Events\ConversationUpdated;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\LeadFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What the buyer told about the purchase along the dialog, and how ready to buy that makes them.
 *
 * The agent hears the fields and keeps them in its dialog state; the score is a rule of this class, so that the
 * sales department can check why a lead is hot.
 */
#[Fillable(['conversation_id', 'contact_id', 'budget', 'payment', 'rooms', 'deadline_year', 'family', 'sells_own_flat', 'wants_viewing'])]
class Lead extends Model
{
    /** @use HasFactory<LeadFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * Of budget, payment, rooms and deadline, how many a hot lead has told.
     */
    public const int HOT_KNOWN_PARAMETERS = 3;

    /**
     * How many a warm lead has told, unless it asked for a viewing or left a phone.
     */
    public const int WARM_KNOWN_PARAMETERS = 2;

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'rooms' => '[]',
        'wants_viewing' => false,
    ];

    /**
     * Score the lead on every save, and tell the open cabinets when it changes.
     */
    protected static function booted(): void
    {
        static::saving(function (Lead $lead): void {
            $lead->score = $lead->scoreByRule();
        });

        // Unlike «saved», these fire only when a row is written: a save that changes nothing tells nobody.
        $changed = fn (Lead $lead) => ConversationUpdated::dispatch($lead->tenant_id, $lead->conversation_id);
        static::created($changed);
        static::updated($changed);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payment' => LeadPayment::class,
            'rooms' => 'array',
            'sells_own_flat' => 'boolean',
            'wants_viewing' => 'boolean',
            'score' => LeadScore::class,
        ];
    }

    /**
     * @return BelongsTo<Conversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * Get the buyer; null for a test conversation.
     *
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * Hot: knows what and how to buy (three of budget, payment, rooms and deadline) and asked for a viewing or left
     * a phone. Warm: two of them, or the viewing or the phone alone. Cold: the rest.
     */
    public function scoreByRule(): LeadScore
    {
        $known = count(array_filter([
            $this->budget !== null,
            $this->payment !== null,
            $this->rooms !== [],
            $this->deadline_year !== null,
        ]));
        $reachable = $this->wants_viewing || $this->contact?->phone !== null;

        return match (true) {
            $known >= self::HOT_KNOWN_PARAMETERS && $reachable => LeadScore::Hot,
            $known >= self::WARM_KNOWN_PARAMETERS || $reachable => LeadScore::Warm,
            default => LeadScore::Cold,
        };
    }
}
