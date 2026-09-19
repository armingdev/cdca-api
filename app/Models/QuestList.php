<?php

namespace App\Models;

use Database\Factories\QuestListFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class QuestList extends Model
{
    /** @use HasFactory<QuestListFactory> */
    use HasFactory;

    protected $fillable = ['user_id', 'name', 'is_public'];

    /**
     * Mirrors the column default, so a list that was just created reads as
     * private without a round trip to the database.
     *
     * @var array<string, mixed>
     */
    protected $attributes = ['is_public' => false];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_public' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<QuestListItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(QuestListItem::class)->orderBy('position');
    }

    /**
     * Lists other users may read and copy: the ones an owner published, and
     * the built-in ones nobody owns.
     *
     * @param  Builder<QuestList>  $query
     */
    public function scopeCommunity(Builder $query, User $viewer): void
    {
        $query->where(fn (Builder $query) => $query->where('is_public', true)->orWhereNull('user_id'))
            ->where(fn (Builder $query) => $query->whereNull('user_id')->orWhere('user_id', '!=', $viewer->id));
    }

    /**
     * Look a list up by name alone, as the console does. Names are only unique
     * per owner, so a built-in list wins over a user's list of the same name
     * and the oldest wins among users — always the same row for the same name.
     *
     * @param  Builder<QuestList>  $query
     */
    public function scopeNamed(Builder $query, string $name): void
    {
        $query->where('name', $name)->orderByRaw('user_id is not null')->orderBy('id');
    }

    /** Whether someone other than the owner may read and copy this list. */
    public function isShared(): bool
    {
        return $this->is_public || $this->user_id === null;
    }

    /**
     * A name this user can still take: the wanted one, or the wanted one with
     * the first free " (n)" — copying or importing "75 Caverns" twice should
     * give a second list, not an error.
     */
    public static function availableNameFor(User $user, string $wanted): string
    {
        $wanted = Str::limit(trim($wanted), 245, '');
        $taken = $user->questLists()
            ->pluck('name')
            ->map(fn (string $name): string => mb_strtolower($name));

        $candidate = $wanted;

        for ($n = 2; $taken->contains(mb_strtolower($candidate)); $n++) {
            $candidate = "{$wanted} ({$n})";
        }

        return $candidate;
    }

    /**
     * Append a catalog quest to the end of the list. (Not named `append` —
     * that collides with Eloquent's appended-attributes method.)
     */
    public function addQuest(int $questId, ?string $label = null): QuestListItem
    {
        return $this->items()->create([
            'position' => (int) $this->items()->max('position') + 1,
            'quest_id' => $questId,
            'label' => $label,
        ]);
    }

    /**
     * Remove the item at a position and close the gap.
     */
    public function removePosition(int $position): bool
    {
        $removed = $this->items()->where('position', $position)->delete();

        if ($removed === 0) {
            return false;
        }

        $this->items()->where('position', '>', $position)->decrement('position');

        return true;
    }
}
