<?php

namespace App\Models;

use Database\Factories\CrewGroupFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A cdca crew: the leader can read the RGA sessions its members chose to
 * share with it. Membership is by invite code; sharing is per RGA and opt-in.
 */
class CrewGroup extends Model
{
    /** @use HasFactory<CrewGroupFactory> */
    use HasFactory;

    private const int INVITE_CODE_LENGTH = 10;

    protected $fillable = [
        'leader_user_id',
        'name',
        'invite_code',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function leader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'leader_user_id');
    }

    /**
     * Everyone in the group, the leader included.
     *
     * @return BelongsToMany<User, $this>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'crew_group_members')->withTimestamps();
    }

    /**
     * RGAs whose sessions the leader may read.
     *
     * @return BelongsToMany<Rga, $this>
     */
    public function sharedRgas(): BelongsToMany
    {
        return $this->belongsToMany(Rga::class, 'crew_group_rgas')
            ->withPivot('shared_by_user_id')
            ->withTimestamps();
    }

    /**
     * @return HasMany<CrewGroupSessionRead, $this>
     */
    public function sessionReads(): HasMany
    {
        return $this->hasMany(CrewGroupSessionRead::class);
    }

    public function isLedBy(User $user): bool
    {
        return $this->leader_user_id === $user->id;
    }

    public function hasMember(User $user): bool
    {
        return $this->members()->whereKey($user->id)->exists();
    }

    /**
     * Start a group with its creator as leader and first member.
     */
    public static function found(User $leader, string $name): self
    {
        return DB::transaction(function () use ($leader, $name): self {
            $group = self::create([
                'leader_user_id' => $leader->id,
                'name' => $name,
                'invite_code' => self::freshInviteCode(),
            ]);

            $group->members()->attach($leader->id);

            return $group;
        });
    }

    /**
     * Take a user out of the group and withdraw every RGA they shared into
     * it — leaving must never leave a session readable behind.
     */
    public function removeMember(User $user): void
    {
        DB::transaction(function () use ($user): void {
            DB::table('crew_group_rgas')
                ->where('crew_group_id', $this->id)
                ->where('shared_by_user_id', $user->id)
                ->delete();

            $this->members()->detach($user->id);
        });
    }

    /**
     * Replace what this member shares with exactly these RGAs (their own,
     * validated upstream). An empty list withdraws everything.
     *
     * @param  list<int>  $rgaIds
     */
    public function replaceSharedRgas(User $member, array $rgaIds): void
    {
        DB::transaction(function () use ($member, $rgaIds): void {
            DB::table('crew_group_rgas')
                ->where('crew_group_id', $this->id)
                ->where('shared_by_user_id', $member->id)
                ->whereNotIn('rga_id', $rgaIds)
                ->delete();

            // Already-shared rows keep their original shared-at time; the
            // unique index makes a concurrent double-save harmless.
            $now = now();

            DB::table('crew_group_rgas')->insertOrIgnore(array_map(fn (int $rgaId): array => [
                'crew_group_id' => $this->id,
                'rga_id' => $rgaId,
                'shared_by_user_id' => $member->id,
                'created_at' => $now,
                'updated_at' => $now,
            ], $rgaIds));
        });
    }

    public function rotateInviteCode(): void
    {
        $this->update(['invite_code' => self::freshInviteCode()]);
    }

    /**
     * Upper-case letters and digits without the look-alikes (0/O, 1/I/L),
     * because people read these out to each other.
     */
    public static function freshInviteCode(): string
    {
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

        do {
            $code = '';

            for ($i = 0; $i < self::INVITE_CODE_LENGTH; $i++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
        } while (self::where('invite_code', $code)->exists());

        return $code;
    }

    /**
     * Invite codes are typed by hand: match them case-insensitively.
     */
    public static function findByInviteCode(string $code): ?self
    {
        return self::where('invite_code', Str::upper(trim($code)))->first();
    }
}
