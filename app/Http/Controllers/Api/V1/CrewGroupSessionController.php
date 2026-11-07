<?php

namespace App\Http\Controllers\Api\V1;

use App\Game\Auth\LoginService;
use App\Http\Controllers\Controller;
use App\Http\Requests\IndexCrewGroupSessionsRequest;
use App\Models\Character;
use App\Models\CrewGroup;
use App\Models\CrewGroupSessionRead;
use App\Models\Rga;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * The crew leader's "copy all sessions": every RGA the members shared, with
 * its session cookies when it has one. Leader only, and every read is
 * written to the audit trail the members can see.
 */
class CrewGroupSessionController extends Controller
{
    public function index(IndexCrewGroupSessionsRequest $request, CrewGroup $crewGroup, LoginService $loginService): JsonResponse
    {
        Gate::authorize('readSessions', $crewGroup);

        $verify = $request->boolean('verify');

        $rgas = $crewGroup->sharedRgas()
            ->with([
                'user:id,name',
                'characters' => fn ($query) => $query->where('is_trustee', false)
                    ->orderByDesc('level')->orderBy('name')->orderBy('id'),
            ])
            ->orderBy('rgas.username')
            ->get();

        $sessions = $rgas->map(function (Rga $rga) use ($verify, $loginService): array {
            $hasSession = $rga->hasSession() && ! empty($rga->cookies['rg_sess_id'] ?? null);
            $alive = $verify && $hasSession ? $loginService->probeSession($rga) : null;
            $live = $hasSession && $alive !== false;

            return [
                'rga_id' => $rga->id,
                'username' => $rga->username,
                'member' => $rga->user === null ? null : ['id' => $rga->user->id, 'name' => $rga->user->name],
                'has_session' => $live,
                'rg_sess_id' => $live ? $rga->cookies['rg_sess_id'] : null,
                'token' => $live ? ($rga->cookies['token'] ?? null) : null,
                'cuserid2' => $live ? ($rga->cookies['cuserid2'] ?? null) : null,
                'status' => $rga->status,
                'last_login_at' => $rga->last_login_at,
                'alive' => $alive,
                'characters' => $rga->characters->map(fn (Character $character): array => [
                    'suid' => $character->suid,
                    'server_id' => $character->server_id,
                    'name' => $character->name,
                    'level' => $character->level,
                ])->values()->all(),
            ];
        })->values();

        CrewGroupSessionRead::create([
            'crew_group_id' => $crewGroup->id,
            'user_id' => $request->user()->id,
            'rga_ids' => $sessions->where('has_session', true)->pluck('rga_id')->values()->all(),
            'verified' => $verify,
            'ip' => $request->ip(),
            'read_at' => now(),
        ]);

        return response()->json(['data' => $sessions]);
    }
}
