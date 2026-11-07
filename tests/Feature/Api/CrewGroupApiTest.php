<?php

use App\Models\Character;
use App\Models\CrewGroup;
use App\Models\CrewGroupSessionRead;
use App\Models\Rga;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->leader = User::factory()->create(['name' => 'Leader']);
    $this->member = User::factory()->create(['name' => 'Member', 'email' => 'member@example.com']);
    $this->outsider = User::factory()->create();

    $this->group = CrewGroup::factory()->create(['leader_user_id' => $this->leader->id, 'name' => 'Night Watch']);
    $this->group->members()->attach($this->member->id);
});

describe('creating and joining', function () {
    it('creates a group led by its creator', function () {
        Sanctum::actingAs($this->outsider);

        $response = $this->postJson('/api/v1/crew-groups', ['name' => 'Raiders'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Raiders')
            ->assertJsonPath('data.is_leader', true)
            ->assertJsonPath('data.members_count', 1);

        expect($response->json('data.invite_code'))->toMatch('/^[A-Z2-9]{10}$/');

        $group = CrewGroup::where('name', 'Raiders')->sole();
        expect($group->leader_user_id)->toBe($this->outsider->id)
            ->and($group->hasMember($this->outsider))->toBeTrue();
    });

    it('requires a name', function () {
        Sanctum::actingAs($this->outsider);

        $this->postJson('/api/v1/crew-groups', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor('name');
    });

    it('joins with an invite code typed in any case', function () {
        Sanctum::actingAs($this->outsider);

        $this->postJson('/api/v1/crew-groups/join', ['invite_code' => ' '.strtolower($this->group->invite_code).' '])
            ->assertOk()
            ->assertJsonPath('data.id', $this->group->id)
            ->assertJsonPath('data.is_leader', false)
            ->assertJsonMissingPath('data.invite_code');

        expect($this->group->hasMember($this->outsider))->toBeTrue();
    });

    it('rejects an unknown invite code', function () {
        Sanctum::actingAs($this->outsider);

        $this->postJson('/api/v1/crew-groups/join', ['invite_code' => 'NOPE234567'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.invite_code.0', 'No crew group has that invite code.');
    });

    it('lists only the groups the user belongs to', function () {
        CrewGroup::factory()->create();
        $mine = CrewGroup::factory()->create(['leader_user_id' => $this->member->id]);
        Sanctum::actingAs($this->member);

        $this->getJson('/api/v1/crew-groups')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['id' => $this->group->id])
            ->assertJsonFragment(['id' => $mine->id]);
    });
});

describe('viewing a group', function () {
    it('shows the leader every share and a member only their own', function () {
        $memberRga = Rga::factory()->for($this->member)->withSession()->create(['username' => 'member-acc']);
        $leaderRga = Rga::factory()->for($this->leader)->withSession()->create(['username' => 'leader-acc']);
        $this->group->replaceSharedRgas($this->member, [$memberRga->id]);
        $this->group->replaceSharedRgas($this->leader, [$leaderRga->id]);

        Sanctum::actingAs($this->leader);
        $this->getJson("/api/v1/crew-groups/{$this->group->id}")
            ->assertOk()
            ->assertJsonPath('data.invite_code', $this->group->invite_code)
            ->assertJsonCount(2, 'data.shared_rgas')
            ->assertJsonCount(2, 'data.members')
            ->assertJsonPath('data.members.1.name', 'Member')
            ->assertJsonPath('data.members.1.shared_rgas_count', 1)
            ->assertJsonMissing(['email' => 'member@example.com']);

        Sanctum::actingAs($this->member);
        $this->getJson("/api/v1/crew-groups/{$this->group->id}")
            ->assertOk()
            ->assertJsonMissingPath('data.invite_code')
            ->assertJsonCount(1, 'data.shared_rgas')
            ->assertJsonPath('data.shared_rgas.0.username', 'member-acc')
            ->assertJsonMissing(['username' => 'leader-acc']);
    });

    it('is hidden from users outside the group', function () {
        Sanctum::actingAs($this->outsider);

        $this->getJson("/api/v1/crew-groups/{$this->group->id}")->assertForbidden();
        $this->getJson("/api/v1/crew-groups/{$this->group->id}/session-reads")->assertForbidden();
    });

    it('requires authentication', function () {
        $this->getJson('/api/v1/crew-groups')->assertUnauthorized();
    });
});

describe('leader-only management', function () {
    it('lets the leader rename and rotate the invite code', function () {
        Sanctum::actingAs($this->leader);
        $oldCode = $this->group->invite_code;

        $this->patchJson("/api/v1/crew-groups/{$this->group->id}", ['name' => 'Day Watch'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Day Watch');

        $newCode = $this->postJson("/api/v1/crew-groups/{$this->group->id}/invite-code")
            ->assertOk()
            ->json('data.invite_code');

        expect($newCode)->not->toBe($oldCode);

        Sanctum::actingAs($this->outsider);
        $this->postJson('/api/v1/crew-groups/join', ['invite_code' => $oldCode])->assertUnprocessable();
        $this->postJson('/api/v1/crew-groups/join', ['invite_code' => $newCode])->assertOk();
    });

    it('refuses management actions from a member', function () {
        Sanctum::actingAs($this->member);

        $this->patchJson("/api/v1/crew-groups/{$this->group->id}", ['name' => 'Mine now'])->assertForbidden();
        $this->postJson("/api/v1/crew-groups/{$this->group->id}/invite-code")->assertForbidden();
        $this->deleteJson("/api/v1/crew-groups/{$this->group->id}")->assertForbidden();

        expect($this->group->fresh()->name)->toBe('Night Watch');
    });

    it('disbands the group and every share with it', function () {
        $rga = Rga::factory()->for($this->member)->withSession()->create();
        $this->group->replaceSharedRgas($this->member, [$rga->id]);
        Sanctum::actingAs($this->leader);

        $this->deleteJson("/api/v1/crew-groups/{$this->group->id}")->assertOk();

        $this->assertModelMissing($this->group);
        $this->assertDatabaseMissing('crew_group_rgas', ['rga_id' => $rga->id]);
        $this->assertDatabaseMissing('crew_group_members', ['user_id' => $this->member->id]);
    });
});

describe('sharing', function () {
    it('replaces the member\'s share set with exactly the listed RGAs', function () {
        [$first, $second] = Rga::factory()->for($this->member)->withSession()->count(2)->create();
        Sanctum::actingAs($this->member);

        $this->putJson("/api/v1/crew-groups/{$this->group->id}/shared-rgas", ['rga_ids' => [$first->id]])
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.rga_id', $first->id);

        $this->putJson("/api/v1/crew-groups/{$this->group->id}/shared-rgas", ['rga_ids' => [$second->id]])
            ->assertOk()
            ->assertJsonPath('data.0.rga_id', $second->id);

        $this->assertDatabaseMissing('crew_group_rgas', ['rga_id' => $first->id]);

        $this->putJson("/api/v1/crew-groups/{$this->group->id}/shared-rgas", ['rga_ids' => []])
            ->assertOk()
            ->assertJsonCount(0, 'data');
    });

    it('refuses to share another user\'s RGA', function () {
        $foreign = Rga::factory()->for($this->outsider)->withSession()->create();
        Sanctum::actingAs($this->member);

        $this->putJson("/api/v1/crew-groups/{$this->group->id}/shared-rgas", ['rga_ids' => [$foreign->id]])
            ->assertUnprocessable()
            ->assertJsonPath('errors', ['rga_ids.0' => ['You can only share your own game accounts.']]);

        $this->assertDatabaseMissing('crew_group_rgas', ['rga_id' => $foreign->id]);
    });

    it('refuses sharing into a group the user is not in', function () {
        $rga = Rga::factory()->for($this->outsider)->withSession()->create();
        Sanctum::actingAs($this->outsider);

        $this->putJson("/api/v1/crew-groups/{$this->group->id}/shared-rgas", ['rga_ids' => [$rga->id]])
            ->assertForbidden();
    });

    it('drops the share when the RGA is deleted', function () {
        $rga = Rga::factory()->for($this->member)->withSession()->create();
        $this->group->replaceSharedRgas($this->member, [$rga->id]);

        $rga->delete();

        $this->assertDatabaseMissing('crew_group_rgas', ['rga_id' => $rga->id]);
    });
});

describe('reading shared sessions', function () {
    it('gives the leader the cookies of shared RGAs only, and audits the read', function () {
        $shared = Rga::factory()->for($this->member)->withSession()->create(['username' => 'alpha']);
        Character::factory()->for($shared)->create(['name' => 'Hero', 'level' => 80]);
        Character::factory()->for($shared)->trustee()->create(['name' => 'Borrowed']);
        $sharedOffline = Rga::factory()->for($this->member)->create(['username' => 'bravo']);
        $notShared = Rga::factory()->for($this->member)->withSession()->create(['username' => 'charlie']);
        $this->group->replaceSharedRgas($this->member, [$shared->id, $sharedOffline->id]);
        Http::fake();
        Sanctum::actingAs($this->leader);

        $this->getJson("/api/v1/crew-groups/{$this->group->id}/sessions")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.username', 'alpha')
            ->assertJsonPath('data.0.member.name', 'Member')
            ->assertJsonPath('data.0.rg_sess_id', $shared->cookies['rg_sess_id'])
            ->assertJsonPath('data.0.characters', [['suid' => $shared->characters()->where('name', 'Hero')->value('suid'), 'server_id' => 1, 'name' => 'Hero', 'level' => 80]])
            ->assertJsonPath('data.1.username', 'bravo')
            ->assertJsonPath('data.1.has_session', false)
            ->assertJsonPath('data.1.rg_sess_id', null)
            ->assertJsonMissing(['username' => 'charlie'])
            ->assertJsonMissing(['rg_sess_id' => $notShared->cookies['rg_sess_id']])
            ->assertJsonMissingPath('data.0.password');

        $read = CrewGroupSessionRead::sole();
        expect($read->user_id)->toBe($this->leader->id)
            ->and($read->rga_ids)->toBe([$shared->id])
            ->and($read->verified)->toBeFalse();
        Http::assertNothingSent();
    });

    it('refuses the sessions to a member and writes no audit row', function () {
        Sanctum::actingAs($this->member);

        $this->getJson("/api/v1/crew-groups/{$this->group->id}/sessions")->assertForbidden();

        expect(CrewGroupSessionRead::count())->toBe(0);
    });

    it('hides a session the game rejects when the leader asks to verify', function () {
        $rga = Rga::factory()->for($this->member)->withSession()->create();
        $this->group->replaceSharedRgas($this->member, [$rga->id]);
        Http::fake(['sigil.outwar.com/ajax/trusteeList.php*' => Http::response('<div>You must be logged in to view this page.</div>')]);
        Sanctum::actingAs($this->leader);

        $this->getJson("/api/v1/crew-groups/{$this->group->id}/sessions?verify=1")
            ->assertOk()
            ->assertJsonPath('data.0.alive', false)
            ->assertJsonPath('data.0.has_session', false)
            ->assertJsonPath('data.0.rg_sess_id', null)
            ->assertJsonPath('data.0.status', Rga::STATUS_INVALID);

        expect(CrewGroupSessionRead::sole()->rga_ids)->toBe([])
            ->and(CrewGroupSessionRead::sole()->verified)->toBeTrue();
    });

    it('shows every member the audit trail', function () {
        CrewGroupSessionRead::create([
            'crew_group_id' => $this->group->id,
            'user_id' => $this->leader->id,
            'rga_ids' => [7],
            'verified' => false,
            'read_at' => now(),
        ]);
        Sanctum::actingAs($this->member);

        $this->getJson("/api/v1/crew-groups/{$this->group->id}/session-reads")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reader.name', 'Leader')
            ->assertJsonPath('data.0.rga_ids', [7]);
    });
});

describe('leaving and removing members', function () {
    it('lets a member leave, withdrawing their shares', function () {
        $rga = Rga::factory()->for($this->member)->withSession()->create();
        $this->group->replaceSharedRgas($this->member, [$rga->id]);
        Sanctum::actingAs($this->member);

        $this->deleteJson("/api/v1/crew-groups/{$this->group->id}/members/{$this->member->id}")->assertOk();

        expect($this->group->hasMember($this->member))->toBeFalse();
        $this->assertDatabaseMissing('crew_group_rgas', ['rga_id' => $rga->id]);
    });

    it('lets the leader remove a member, withdrawing their shares', function () {
        $rga = Rga::factory()->for($this->member)->withSession()->create();
        $this->group->replaceSharedRgas($this->member, [$rga->id]);
        Sanctum::actingAs($this->leader);

        $this->deleteJson("/api/v1/crew-groups/{$this->group->id}/members/{$this->member->id}")->assertOk();

        expect($this->group->hasMember($this->member))->toBeFalse();
        $this->assertDatabaseMissing('crew_group_rgas', ['rga_id' => $rga->id]);
    });

    it('stops a member removing someone else, and the leader leaving', function () {
        $other = User::factory()->create();
        $this->group->members()->attach($other->id);

        Sanctum::actingAs($this->member);
        $this->deleteJson("/api/v1/crew-groups/{$this->group->id}/members/{$other->id}")->assertForbidden();

        Sanctum::actingAs($this->leader);
        $this->deleteJson("/api/v1/crew-groups/{$this->group->id}/members/{$this->leader->id}")->assertForbidden();

        expect($this->group->hasMember($other))->toBeTrue()
            ->and($this->group->hasMember($this->leader))->toBeTrue();
    });
});
