<?php

use App\Http\Controllers\Api\V1\AttackListController;
use App\Http\Controllers\Api\V1\AttackListTargetController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CharacterController;
use App\Http\Controllers\Api\V1\CharacterQuestProgressController;
use App\Http\Controllers\Api\V1\CharacterSkillController;
use App\Http\Controllers\Api\V1\CharacterTeleportController;
use App\Http\Controllers\Api\V1\CrewController;
use App\Http\Controllers\Api\V1\CrewGroupController;
use App\Http\Controllers\Api\V1\CrewGroupInviteCodeController;
use App\Http\Controllers\Api\V1\CrewGroupMemberController;
use App\Http\Controllers\Api\V1\CrewGroupSessionController;
use App\Http\Controllers\Api\V1\CrewGroupSessionReadController;
use App\Http\Controllers\Api\V1\CrewGroupSharedRgaController;
use App\Http\Controllers\Api\V1\PasswordController;
use App\Http\Controllers\Api\V1\QuestController;
use App\Http\Controllers\Api\V1\QuestListController;
use App\Http\Controllers\Api\V1\QuestListItemController;
use App\Http\Controllers\Api\V1\RgaChallengeTokenController;
use App\Http\Controllers\Api\V1\RgaController;
use App\Http\Controllers\Api\V1\RgaSessionController;
use App\Http\Controllers\Api\V1\RunController;
use App\Http\Controllers\Api\V1\SkillController;
use App\Http\Controllers\Api\V1\StatsController;
use App\Http\Controllers\Api\V1\WorldController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // Public auth endpoints (rate-limited).
    Route::post('register', [AuthController::class, 'register'])->middleware('throttle:auth');
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:auth');
    Route::post('forgot-password', [PasswordController::class, 'forgot'])->middleware('throttle:auth');
    Route::post('reset-password', [PasswordController::class, 'reset'])->middleware('throttle:auth');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('user', [AuthController::class, 'user']);
        Route::put('user/password', [PasswordController::class, 'update'])->middleware('throttle:auth');

        // RGAs (game accounts) + their session actions. `rgas/sessions` must be
        // registered before the resource, or `rgas/{rga}` swallows "sessions".
        Route::get('rgas/sessions', [RgaSessionController::class, 'index'])->middleware('throttle:30,1');
        Route::apiResource('rgas', RgaController::class);
        Route::post('rgas/{rga}/login', [RgaController::class, 'login']);
        Route::post('rgas/{rga}/session', [RgaController::class, 'attachSession']);
        Route::get('rgas/{rga}/session', [RgaController::class, 'showSession']);
        Route::post('rgas/{rga}/sync-characters', [RgaController::class, 'syncCharacters']);
        Route::post('rgas/{rga}/refresh-stats', [RgaController::class, 'refreshStats']);
        Route::get('rgas/{rga}/challenge-tokens', [RgaChallengeTokenController::class, 'show']);
        Route::post('rgas/{rga}/challenge-tokens/claim', [RgaChallengeTokenController::class, 'claim']);

        // Crew groups (crew leader mode): members opt in to sharing RGA sessions
        // with the leader. `crew-groups/join` sits before the resource routes.
        Route::post('crew-groups/join', [CrewGroupMemberController::class, 'store'])->middleware('throttle:auth');
        Route::apiResource('crew-groups', CrewGroupController::class);
        Route::post('crew-groups/{crewGroup}/invite-code', [CrewGroupInviteCodeController::class, 'store']);
        Route::put('crew-groups/{crewGroup}/shared-rgas', [CrewGroupSharedRgaController::class, 'update']);
        Route::delete('crew-groups/{crewGroup}/members/{user}', [CrewGroupMemberController::class, 'destroy']);
        Route::get('crew-groups/{crewGroup}/sessions', [CrewGroupSessionController::class, 'index'])->middleware('throttle:30,1');
        Route::get('crew-groups/{crewGroup}/session-reads', [CrewGroupSessionReadController::class, 'index']);

        // Characters + per-character skill selection / casting.
        Route::apiResource('characters', CharacterController::class)->only(['index', 'show']);
        Route::get('characters/{character}/skills', [CharacterSkillController::class, 'index']);
        Route::put('characters/{character}/skills', [CharacterSkillController::class, 'update']);
        Route::post('characters/{character}/skills/sync', [CharacterSkillController::class, 'sync']);
        Route::post('characters/{character}/skills/{skill}/train', [CharacterSkillController::class, 'train']);
        Route::post('characters/{character}/cast', [CharacterSkillController::class, 'cast']);
        Route::post('characters/{character}/refresh-stats', [CharacterController::class, 'refreshStats']);

        // Teleports: anchors the character can jump with, and travelling.
        Route::get('characters/{character}/teleports', [CharacterTeleportController::class, 'index']);
        Route::post('characters/{character}/teleports/sync', [CharacterTeleportController::class, 'sync']);
        Route::post('characters/{character}/teleports', [CharacterTeleportController::class, 'store']);
        Route::post('characters/{character}/home-tavern', [CharacterTeleportController::class, 'setHomeTavern']);

        // Per-character quest memory: what runs will skip, and how to forget it.
        Route::get('characters/{character}/quest-progress', [CharacterQuestProgressController::class, 'index']);
        Route::delete('characters/{character}/quest-progress', [CharacterQuestProgressController::class, 'destroy']);
        Route::delete('rgas/{rga}/quest-progress', [CharacterQuestProgressController::class, 'destroyForRga']);

        Route::get('characters/{character}/battles', [StatsController::class, 'battles']);
        Route::get('characters/{character}/stats', [StatsController::class, 'summary']);
        Route::get('stats/drops', [StatsController::class, 'drops']);

        // Skill catalog (read-only).
        Route::get('skills', [SkillController::class, 'index']);

        // Quest catalog (read-only, crawled from the game).
        Route::get('quests', [QuestController::class, 'index']);
        Route::get('quests/givers', [QuestController::class, 'givers']);
        Route::get('quests/{quest}', [QuestController::class, 'show']);

        // World data (read-only).
        Route::get('world/rooms/{room}', [WorldController::class, 'showRoom']);
        Route::get('world/mobs', [WorldController::class, 'mobs']);
        Route::get('crews', [CrewController::class, 'index']);

        // Quest lists + their ordered items.
        Route::post('quest-lists/import', [QuestListController::class, 'import']);
        Route::post('quest-lists/{questList}/copy', [QuestListController::class, 'copy']);
        Route::apiResource('quest-lists', QuestListController::class);
        Route::post('quest-lists/{questList}/items', [QuestListItemController::class, 'store']);
        Route::delete('quest-lists/{questList}/items/{position}', [QuestListItemController::class, 'destroy']);

        Route::apiResource('attack-lists', AttackListController::class)->except(['update']);
        Route::post('attack-lists/{attackList}/targets', [AttackListTargetController::class, 'store']);
        Route::delete('attack-lists/{attackList}/targets/{position}', [AttackListTargetController::class, 'destroy']);

        // Runs — the automation engine.
        Route::apiResource('runs', RunController::class)->only(['index', 'store', 'show', 'update', 'destroy']);
        Route::post('runs/{run}/stop', [RunController::class, 'stop']);
        Route::post('runs/{run}/pause', [RunController::class, 'pause']);
        Route::post('runs/{run}/resume', [RunController::class, 'resume']);
        Route::get('runs/{run}/battles', [RunController::class, 'battles']);
        Route::get('runs/{run}/drops', [RunController::class, 'drops']);
        Route::get('runs/{run}/events', [RunController::class, 'events']);
    });
});
