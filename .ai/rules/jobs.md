---
paths:
  - 'app/Jobs/Run*.php'
---

# Jobs

## Parking is inert without the scheduler
A `RunJob` parks a participant by returning a `ParticipantOutcome` with status `Waiting` and a `resume_at`; it does **not** dispatch anything itself. The only thing that re-drives a parked participant is `outwar:runs-resume-due`, scheduled every minute in `routes/console.php`. If that schedule is not running, every parked run — respawn waits, rage waits, Circumspect waits, session recovery — sits at "Waiting" forever with no error anywhere.

So: deployments need `schedule:work` (or a system cron) alongside Horizon, and a "run stopped progressing" report should check the scheduler before the engine. A self-dispatching delayed job was tried and removed as redundant — if you reach for it again, note that it doubles the queue pushes and every `Queue::assertPushed(RunXJob::class, N)` in the suite counts both.

Each dispatch mints a fresh `dispatch_token` on the participant, and a delivery whose token no longer matches no-ops. That is what makes pause→resume, restart, and delayed starts idempotent — keep it that way for any new dispatch path.
