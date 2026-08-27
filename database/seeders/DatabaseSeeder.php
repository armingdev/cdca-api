<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(SkillSeeder::class);
        $this->call(JunkItemSeeder::class);
        $this->call(QuestItemSeeder::class);
        $this->call(RoomSeeder::class);
        $this->call(AreaSeeder::class);
        $this->call(ZoneSeeder::class);
        $this->call(MobSeeder::class);
        $this->call(QuestSeeder::class);
        $this->call(BossSeeder::class);
        $this->call(QuestListSeeder::class);
        $this->call(TeleportAnchorSeeder::class);
    }
}
