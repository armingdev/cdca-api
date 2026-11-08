---
paths:
  - 'app/Game/Skills/**'
---

# Skills

## A null server window is a reading, not an absence
`buff_until`/`recharge_until` being null is ambiguous on its own: it means both "never read" and "the game says it is not active". `buff_synced_at`/`recharge_synced_at` disambiguate — a reading newer than `last_cast_at` wins over the local `last_cast_at + duration` estimate (see CharacterSkill::serverReadingSupersedesLastCast).

This is load-bearing: plenty of skills have duration > cooldown (Empower: 180m buff, 120m cooldown), so without it the estimate claims "already active" for a buff the game says has gone, and the skill is never re-cast. That was the "only 5 of 9 skills cast" bug.

Any new code path that writes those windows MUST stamp the matching `*_synced_at`, and `SkillCaster::cast()` must keep clearing both so the estimate re-arms after a cast.

## Misc skills: missing from the Misc tab means not owned
Misc skills are acquired, never trained, and the Misc tab lists only the ones the character owns. A Misc skill selected for a run but absent there used to stay `synced_at = null`, which BuffEnsurer reads as "not known yet, try it" — so it was cast, refused and retried daily (278 refusals in prod, Nov 2026). `SkillSyncService::recordUnacquiredMisc()` turns that absence into a reading (synced, level 0, refusal backoff cleared) and the caster skips it as `not_acquired`. Never infer "not owned" from any other tab: school tabs list every skill of the school, owned or not.
