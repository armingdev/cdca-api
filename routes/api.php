<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CharacterController;
use App\Http\Controllers\Api\V1\CharacterSkillController;
use App\Http\Controllers\Api\V1\QuestListController;
use App\Http\Controllers\Api\V1\QuestListItemController;
use App\Http\Controllers\Api\V1\RgaController;
use App\Http\Controllers\Api\V1\RunController;
use App\Http\Controllers\Api\V1\SkillController;
use App\Http\Controllers\Api\V1\StatsController;
use App\Http\Controllers\Api\V1\WorldController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // Public auth endpoints (rate-limited).
    Route::post('register', [AuthController::class, 'register'])->middleware('throttle:auth');
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:auth');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('user', [AuthController::class, 'user']);

        // RGAs (game accounts) + their session actions.
        Route::apiResource('rgas', RgaController::class)->except(['update']);
        Route::post('rgas/{rga}/login', [RgaController::class, 'login']);
        Route::post('rgas/{rga}/sync-characters', [RgaController::class, 'syncCharacters']);

        // Characters + per-character skill selection / casting.
        Route::apiResource('characters', CharacterController::class)->only(['index', 'show']);
        Route::get('characters/{character}/skills', [CharacterSkillController::class, 'index']);
        Route::put('characters/{character}/skills', [CharacterSkillController::class, 'update']);
        Route::post('characters/{character}/skills/sync', [CharacterSkillController::class, 'sync']);
        Route::post('characters/{character}/skills/{skill}/train', [CharacterSkillController::class, 'train']);
        Route::post('characters/{character}/cast', [CharacterSkillController::class, 'cast']);
        Route::get('characters/{character}/battles', [StatsController::class, 'battles']);
        Route::get('characters/{character}/stats', [StatsController::class, 'summary']);

        // Skill catalog (read-only).
        Route::get('skills', [SkillController::class, 'index']);

        // World data (read-only).
        Route::get('world/rooms/{room}', [WorldController::class, 'showRoom']);
        Route::get('world/mobs', [WorldController::class, 'mobs']);

        // Quest lists + their ordered items.
        Route::apiResource('quest-lists', QuestListController::class)->except(['update']);
        Route::post('quest-lists/{questList}/items', [QuestListItemController::class, 'store']);
        Route::delete('quest-lists/{questList}/items/{position}', [QuestListItemController::class, 'destroy']);

        // Runs — the automation engine.
        Route::apiResource('runs', RunController::class)->only(['index', 'store', 'show']);
        Route::post('runs/{run}/stop', [RunController::class, 'stop']);
        Route::get('runs/{run}/battles', [RunController::class, 'battles']);
    });
});
