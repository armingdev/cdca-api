<?php

use App\Models\Quest;
use App\Models\QuestList;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->user = User::factory()->create();
    Sanctum::actingAs($this->user);
});

it('creates, lists, and shows quest lists scoped to the user', function () {
    $this->postJson('/api/v1/quest-lists', ['name' => 'Armins List'])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Armins List');

    QuestList::factory()->for(User::factory())->create(); // someone else's

    $this->getJson('/api/v1/quest-lists')->assertOk()->assertJsonCount(1, 'data');
});

it('adds and removes catalog quests, keeping positions contiguous', function () {
    $list = QuestList::factory()->for($this->user)->create();
    $street = Quest::factory()->create(['name' => 'Street Crawler', 'giver' => 'Stella']);
    $church = Quest::factory()->create(['name' => 'Cleansing the Church', 'giver' => 'Stella']);

    $this->postJson("/api/v1/quest-lists/{$list->id}/items", ['quest_id' => $street->id, 'label' => 'First!'])
        ->assertOk()
        ->assertJsonPath('data.items.0.quest_id', $street->id)
        ->assertJsonPath('data.items.0.quest.name', 'Street Crawler')
        ->assertJsonPath('data.items.0.quest.giver', 'Stella')
        ->assertJsonPath('data.items.0.display_name', 'First!');

    $this->postJson("/api/v1/quest-lists/{$list->id}/items", ['quest_id' => $church->id])
        ->assertOk()
        ->assertJsonCount(2, 'data.items');

    $this->deleteJson("/api/v1/quest-lists/{$list->id}/items/1")
        ->assertOk()
        ->assertJsonCount(1, 'data.items')
        ->assertJsonPath('data.items.0.quest_id', $church->id)
        ->assertJsonPath('data.items.0.position', 1);
});

it('rejects quest ids that are not in the catalog', function () {
    $list = QuestList::factory()->for($this->user)->create();

    $this->postJson("/api/v1/quest-lists/{$list->id}/items", ['quest_id' => 999999])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('quest_id');
});

it('forbids touching another user\'s quest list', function () {
    $other = QuestList::factory()->for(User::factory())->create();
    $quest = Quest::factory()->create();

    $this->getJson("/api/v1/quest-lists/{$other->id}")->assertForbidden();
    $this->postJson("/api/v1/quest-lists/{$other->id}/items", ['quest_id' => $quest->id])->assertForbidden();
});

describe('importing a quest list', function () {
    it('builds a list from an uploaded dDCT file, in the file\'s order', function () {
        $names = json_decode(file_get_contents(base_path('tests/Fixtures/quest-lists/75 Caverns.dql')), true)['QuestNames'];
        foreach ($names as $name) {
            Quest::factory()->create(['name' => $name]);
        }

        $response = $this->post('/api/v1/quest-lists/import', [
            'file' => new UploadedFile(base_path('tests/Fixtures/quest-lists/75 Caverns.dql'), '75 Caverns.dql', null, null, true),
        ], ['Accept' => 'application/json']);

        $response->assertCreated()
            ->assertJsonPath('data.name', '75 Caverns')
            ->assertJsonPath('data.items.*.quest.name', $names)
            ->assertJsonPath('import', ['imported' => 18, 'unmatched' => [], 'ambiguous' => []]);

        expect($this->user->questLists()->sole()->items()->count())->toBe(18);
    });

    it('accepts pasted text with quest names and game quest ids, ignoring comments and blank lines', function () {
        Quest::factory()->create(['name' => 'Family Insurance']);
        Quest::factory()->create(['name' => 'Escaping the Hive', 'game_quest_id' => 2071]);

        $this->postJson('/api/v1/quest-lists/import', [
            'name' => 'Pasted',
            'text' => "# caverns\nfamily insurance\n\n2071\n",
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Pasted')
            ->assertJsonPath('data.items.*.quest.name', ['Family Insurance', 'Escaping the Hive']);
    });

    it('imports what it can and reports the lines it could not place', function () {
        Quest::factory()->create(['name' => 'Family Insurance']);

        $this->postJson('/api/v1/quest-lists/import', [
            'text' => json_encode(['Name' => 'Partly Known', 'QuestNames' => ['Family Insurance', 'A Quest Nobody Mapped']]),
        ])
            ->assertCreated()
            ->assertJsonPath('import.imported', 1)
            ->assertJsonPath('import.unmatched', ['A Quest Nobody Mapped']);
    });

    it('uses the lowest-level quest for a name the catalog has twice and says so', function () {
        $high = Quest::factory()->create(['name' => 'Aura EXP', 'required_level' => 80]);
        $low = Quest::factory()->create(['name' => 'Aura EXP', 'required_level' => 20]);

        $this->postJson('/api/v1/quest-lists/import', ['name' => 'Auras', 'text' => 'Aura EXP'])
            ->assertCreated()
            ->assertJsonPath('data.items.0.quest_id', $low->id)
            ->assertJsonPath('import.ambiguous', ['Aura EXP']);
    });

    it('gives a second import of the same list its own name instead of failing', function () {
        Quest::factory()->create(['name' => 'Family Insurance']);
        $payload = ['text' => json_encode(['Name' => '75 Caverns', 'QuestNames' => ['Family Insurance']])];

        $this->postJson('/api/v1/quest-lists/import', $payload)->assertCreated();
        $this->postJson('/api/v1/quest-lists/import', $payload)
            ->assertCreated()
            ->assertJsonPath('data.name', '75 Caverns (2)');
    });

    it('returns 422 and creates nothing when the content is not a usable list', function (array $payload, string $message) {
        Quest::factory()->create(['name' => 'Family Insurance']);

        $this->postJson('/api/v1/quest-lists/import', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('message', $message);

        expect(QuestList::count())->toBe(0);
    })->with([
        'no quest is in the catalog' => [
            ['name' => 'Ghosts', 'text' => "Nope\nAlso Nope"],
            'None of the 2 quests in the file are in the catalog.',
        ],
        'a dDCT file without quest names' => [
            ['text' => '{"Name":"Broken"}'],
            'This looks like a dDCT quest list, but it has no "QuestNames" to read.',
        ],
        'pasted text without a name' => [
            ['text' => 'Family Insurance'],
            'The list needs a name.',
        ],
    ]);

    it('returns 422 for a file that is not text', function () {
        $this->post('/api/v1/quest-lists/import', [
            'file' => UploadedFile::fake()->image('list.png'),
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file' => 'The file must be a text or JSON quest list.']);
    });

    it('returns 422 when neither a file nor text is sent', function () {
        $this->postJson('/api/v1/quest-lists/import', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file', 'text']);
    });
});

describe('community quest lists', function () {
    it('lists published and built-in lists from others, never private or own ones', function () {
        $published = QuestList::factory()->for(User::factory()->state(['name' => 'Krim']))->public()->create();
        $builtIn = QuestList::factory()->create(['user_id' => null]);
        QuestList::factory()->for(User::factory())->create();
        QuestList::factory()->for($this->user)->public()->create();

        $response = $this->getJson('/api/v1/quest-lists?scope=community')->assertOk();

        expect($response->json('data.*.id'))->toEqualCanonicalizing([$published->id, $builtIn->id]);
        $response->assertJsonPath('data.'.array_search($published->id, $response->json('data.*.id')).'.shared_by', 'Krim');
    });

    it('lets a user read a published list but not a private one', function () {
        $published = QuestList::factory()->for(User::factory())->public()->create();
        $private = QuestList::factory()->for(User::factory())->create();

        $this->getJson("/api/v1/quest-lists/{$published->id}")->assertOk()->assertJsonPath('data.is_mine', false);
        $this->getJson("/api/v1/quest-lists/{$private->id}")->assertForbidden();
    });

    it('copies a published list with its quests into the user\'s own lists', function () {
        $source = QuestList::factory()->for(User::factory())->public()->create(['name' => 'Road to 95']);
        $source->addQuest(Quest::factory()->create()->id, 'first');
        $source->addQuest(Quest::factory()->create()->id);

        $this->postJson("/api/v1/quest-lists/{$source->id}/copy")
            ->assertCreated()
            ->assertJsonPath('data.name', 'Road to 95')
            ->assertJsonPath('data.is_mine', true)
            ->assertJsonPath('data.is_public', false)
            ->assertJsonPath('data.items.*.quest_id', $source->items()->pluck('quest_id')->all())
            ->assertJsonPath('data.items.0.label', 'first');

        expect($source->items()->count())->toBe(2);
    });

    it('returns 403 when copying a private list of another user', function () {
        $private = QuestList::factory()->for(User::factory())->create();

        $this->postJson("/api/v1/quest-lists/{$private->id}/copy")->assertForbidden();

        expect($this->user->questLists()->count())->toBe(0);
    });

    it('returns 403 when changing a published list that belongs to someone else', function () {
        $published = QuestList::factory()->for(User::factory())->public()->create(['name' => 'Theirs']);
        $quest = Quest::factory()->create();

        $this->patchJson("/api/v1/quest-lists/{$published->id}", ['name' => 'Mine'])->assertForbidden();
        $this->postJson("/api/v1/quest-lists/{$published->id}/items", ['quest_id' => $quest->id])->assertForbidden();
        $this->deleteJson("/api/v1/quest-lists/{$published->id}")->assertForbidden();

        expect($published->fresh()->name)->toBe('Theirs');
    });

    it('publishes and renames the user\'s own list', function () {
        $list = QuestList::factory()->for($this->user)->create(['name' => 'Draft']);

        $this->patchJson("/api/v1/quest-lists/{$list->id}", ['name' => 'Sub85 Veldara', 'is_public' => true])
            ->assertOk()
            ->assertJsonPath('data.name', 'Sub85 Veldara')
            ->assertJsonPath('data.is_public', true);
    });

    it('lets two users have a list of the same name but not one user twice', function () {
        QuestList::factory()->for(User::factory())->create(['name' => '75 Caverns']);

        $this->postJson('/api/v1/quest-lists', ['name' => '75 Caverns'])->assertCreated();
        $this->postJson('/api/v1/quest-lists', ['name' => '75 Caverns'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    });
});
