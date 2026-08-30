---
paths:
  - 'app/Game/Skills/**'
---

# Skills

## A null server window is a reading, not an absence
`buff_until`/`recharge_until` being null is ambiguous on its own: it means both "never read" and "the game says it is not active". `buff_synced_at`/`recharge_synced_at` disambiguate — a reading newer than `last_cast_at` wins over the local `last_cast_at + duration` estimate (see CharacterSkill::serverReadingSupersedesLastCast).

This is load-bearing: plenty of skills have duration > cooldown (Empower: 180m buff, 120m cooldown), so without it the estimate claims "already active" for a buff the game says has gone, and the skill is never re-cast. That was the "only 5 of 9 skills cast" bug.

Any new code path that writes those windows MUST stamp the matching `*_synced_at`, and `SkillCaster::cast()` must keep clearing both so the estimate re-arms after a cast.
