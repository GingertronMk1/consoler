<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class MutGGThemeTeam extends Command
{
    private const bool OUTPUT_CSV = false;

    private const string PLAYER_BASE_URL = 'https://www.mut.gg/players/';

    private const string API_URL = 'https://www.mut.gg/api/25';

    private const string PLAYER_API_URL = self::API_URL.'/player-items';

    public const array CHEMS = [
        'baltimore-ravens' => 6250,
        'new-england-patriots' => 6220,
        'philadelphia-eagles' => 6130,
        'seattle-seahawks' => 6280,
    ];

    private const int MAX_CHEM_COUNT = 3;

    private const array POSITIONS = [
        1 => 2, // QB
        2 => 3, // HB
        3 => 2, // FB
        4 => 5, // WR
        5 => 3, // TE
        6 => 2, // LT
        7 => 2, // LG
        8 => 2, // C
        9 => 2, // RG
        10 => 2, // RT
        11 => 2, // LE
        12 => 2, // RE
        13 => 4, // DT
        14 => 2, // LOLB
        15 => 4, // MLB
        16 => 2, // ROLB
        17 => 5, // CB
        18 => 2, // FS
        19 => 2, // SS
        20 => 1, // K
        21 => 1, // P
    ];

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:mut-g-g-theme-team {count=2}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $progressBar = $this->output->createProgressBar(count($this->getChems()) * count(self::POSITIONS) * self::MAX_CHEM_COUNT);
        $progressBar->start();
        $allPlayers = [];
        foreach ($this->getChems() as $slug => $id) {
            foreach (self::POSITIONS as $position => $number) {
                for ($count = self::MAX_CHEM_COUNT; $count > 0; $count--) {
                    $query = [
                        'max_ovr' => 'on',
                        'positions' => $position,
                        'chemistry' => "{$id}-{$count}",
                    ];
                    $players = Cache::remember(
                        http_build_query($query),
                        now()->addDay(),
                        fn () => Http::get(
                            self::PLAYER_API_URL,
                            $query
                        )->json()['data'],
                    );

                    $allPlayers[$position] ??= [];
                    foreach ($players as $player) {
                        if (! in_array(
                            $player['player']['id'],
                            array_map(
                                fn (array $searchPLayer) => $searchPLayer['player']['id'],
                                $allPlayers[$position]
                            ))) {
                            $allPlayers[$position][] = $player;
                        }
                    }
                    $progressBar->advance();
                }
            }
        }
        $progressBar->finish();
        $this->newLine();

        foreach ($allPlayers as $position => $players) {
            $this->output->section($position);
            $this->table(
                ['Max OVR', 'Name'],
                array_map(
                    fn (array $player) => [$player['maxOverall'], "{$player['firstName']} {$player['lastName']}"],
                    $players
                )
            );
        }

        return self::SUCCESS;
    }

    private function getChems(): array
    {
        return array_slice(self::CHEMS, 0, $this->argument('count', 2));
    }
}
