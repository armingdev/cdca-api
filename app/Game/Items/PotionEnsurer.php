<?php

namespace App\Game\Items;

use App\Game\Engine\RunEventRecorder;
use App\Game\Enums\PotionActivationOutcome;
use App\Game\Enums\PotionKind;
use App\Game\Enums\RunEventType;
use App\Game\Exceptions\GameException;
use App\Game\Exceptions\SessionCollisionException;
use App\Models\Character;
use App\Models\CharacterPotion;
use App\Models\Potion;
use App\Models\RunEvent;
use Carbon\CarbonInterface;
use Closure;

/**
 * Keeps a run's buff potions active, the potion counterpart of BuffEnsurer:
 * called just before combat, it drinks whatever selected potion is not
 * active, and is otherwise a clock comparison until the earliest moment a
 * decision could change (an effect about to lapse, a refusal backing off).
 */
class PotionEnsurer
{
    /** Re-drink this long before the game says the effect ends. */
    private const int EXPIRY_MARGIN_MINUTES = 2;

    /** A failed read waits this long before the next attempt. */
    private const int RETRY_AFTER_ERROR_MINUTES = 5;

    /** Never re-read the game more often than this, whatever the windows say. */
    private const int MIN_RECHECK_SECONDS = 60;

    private ?CarbonInterface $recheckAt = null;

    /** @var array<int, true> potions already reported as used up this pass */
    private array $reportedEmpty = [];

    /**
     * @param  list<int>  $potionIds  the run's buff_potion_ids
     */
    public function __construct(
        private readonly PotionService $service,
        private readonly array $potionIds,
    ) {}

    /**
     * @param  list<int>  $potionIds
     */
    public static function forCharacter(Character $character, array $potionIds): self
    {
        return new self(PotionService::forCharacter($character), $potionIds);
    }

    public function hasWork(): bool
    {
        return $this->potionIds !== [];
    }

    /**
     * @param  Closure(string): void|null  $log
     */
    public function ensure(?Closure $log = null, ?RunEventRecorder $events = null): void
    {
        if (! $this->hasWork() || ($this->recheckAt !== null && $this->recheckAt->isFuture())) {
            return;
        }

        $log ??= fn (string $message) => null;

        try {
            $this->recheckAt = $this->pass($log, $events);
        } catch (SessionCollisionException $exception) {
            throw $exception;
        } catch (GameException $exception) {
            // A page that would not read costs this pass its potions, not the run.
            $log("Potion check failed: {$exception->getMessage()}");
            $events?->record(RunEventType::PotionSkipped, "Potion check failed: {$exception->getMessage()}", [
                'reason' => 'read_failed',
            ], RunEvent::LEVEL_WARNING);
            $this->recheckAt = now()->addMinutes(self::RETRY_AFTER_ERROR_MINUTES);
        }
    }

    /**
     * @param  Closure(string): void  $log
     * @return CarbonInterface when the next pass is due
     */
    private function pass(Closure $log, ?RunEventRecorder $events): CarbonInterface
    {
        $stacks = $this->service->sync();
        $potions = Potion::whereIn('id', $this->potionIds)->where('kind', PotionKind::Buff)->get()->keyBy('id');
        $due = [];

        foreach ($this->potionIds as $potionId) {
            $potion = $potions->get($potionId);

            if ($potion === null) {
                continue;
            }

            $stack = $stacks->get($potionId);

            if ($stack?->isActive()) {
                $due[] = $this->renewAt($stack);

                continue;
            }

            if ($stack === null || $stack->quantity === 0) {
                $this->reportEmpty($potion, $log, $events);

                continue;
            }

            if ($stack->isRefused()) {
                $due[] = $stack->refused_until;

                continue;
            }

            $result = $this->service->activate($potion);

            match ($result->outcome) {
                PotionActivationOutcome::Activated => $this->reportUsed($potion, $result->stack, $log, $events),
                PotionActivationOutcome::OutOfStock => $this->reportEmpty($potion, $log, $events),
                PotionActivationOutcome::AlreadyActive => null,
                PotionActivationOutcome::Refused => $this->reportRefused($potion, $result->message, $log, $events),
            };

            $stack = $result->stack;

            if ($stack?->isActive()) {
                $due[] = $this->renewAt($stack);
            } elseif ($stack?->isRefused()) {
                $due[] = $stack->refused_until;
            }
        }

        $next = $due === [] ? now()->addDay() : collect($due)->min();
        $floor = now()->addSeconds(self::MIN_RECHECK_SECONDS);

        return $next->lessThan($floor) ? $floor : $next;
    }

    private function renewAt(CharacterPotion $stack): CarbonInterface
    {
        return $stack->active_until->copy()->subMinutes(self::EXPIRY_MARGIN_MINUTES);
    }

    /**
     * @param  Closure(string): void  $log
     */
    private function reportUsed(Potion $potion, ?CharacterPotion $stack, Closure $log, ?RunEventRecorder $events): void
    {
        $left = $stack->quantity ?? 0;
        $log("Drank {$potion->name} ({$left} left).");
        $events?->record(RunEventType::PotionUsed, "Drank {$potion->name} ({$left} left).", [
            'potion_id' => $potion->id,
            'potion' => $potion->name,
            'kind' => $potion->kind->value,
            'quantity_left' => $left,
            'active_until' => $stack?->active_until?->toIso8601String(),
        ]);
    }

    /**
     * @param  Closure(string): void  $log
     */
    private function reportEmpty(Potion $potion, Closure $log, ?RunEventRecorder $events): void
    {
        if (isset($this->reportedEmpty[$potion->id])) {
            return;
        }

        $this->reportedEmpty[$potion->id] = true;
        $log("No {$potion->name} left — continuing without it.");
        $events?->record(RunEventType::PotionSkipped, "No {$potion->name} left — continuing without it.", [
            'potion_id' => $potion->id,
            'potion' => $potion->name,
            'reason' => 'out_of_stock',
        ], RunEvent::LEVEL_WARNING);
    }

    /**
     * @param  Closure(string): void  $log
     */
    private function reportRefused(Potion $potion, string $message, Closure $log, ?RunEventRecorder $events): void
    {
        $log("{$potion->name} refused: {$message}");
        $events?->record(RunEventType::PotionSkipped, "{$potion->name} refused: {$message}", [
            'potion_id' => $potion->id,
            'potion' => $potion->name,
            'reason' => 'refused',
        ], RunEvent::LEVEL_WARNING);
    }
}
