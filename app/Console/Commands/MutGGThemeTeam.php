<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\DomCrawler\Crawler;

class MutGGThemeTeam extends Command
{
    private const string PLAYER_BASE_URL = 'https://www.mut.gg/players/';

    private const string API_URL = 'https://www.mut.gg/api/25';

    private const string PLAYER_API_URL = self::API_URL.'/player-items';

    private const array CHEMS = [
        'seahawks' => '6280',
        'eagles' => '6130',
        'patriots' => '6220',
        //        'chiefs' => '6090',
    ];

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
    protected $signature = 'app:mut-g-g-theme-team';

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

        $coreData = Http::get(self::API_URL.'/core-data')->json()['data'];
        $pages = [];
        foreach (self::CHEMS as $teamName => $chemId) {
            foreach (array_keys(self::POSITIONS) as $position) {
                for ($page = 1; $page < 2; $page++) {
                    $pages[] = [
                        'chemistry' => "{$chemId}-1",
                        'teamName' => $teamName,
                        'page' => $page,
                        'positions' => $position,
                    ];

                }
            }
        }

        $gettingIdsProgressBar = $this->output->createProgressBar(count($pages));

        $playerIds = [];

        $gettingIdsProgressBar->start();

        foreach ($pages as $page) {
            $gettingIdsProgressBar->advance();
            $pagePlayers = Http::get(
                self::PLAYER_BASE_URL,
                $page
            );
            $crawler = new Crawler($pagePlayers->body());
            $crawler = $crawler->filter('[data-external-id]');
            $crawler->each(function (Crawler $node) use (&$playerIds) {
                $playerId = $node->attr('data-external-id');
                $playerIds[] = $playerId;
            });
        }
        $gettingIdsProgressBar->finish();

        $players = [];

        $chunkedIds = array_chunk($playerIds, 10);

        $gettingPlayersProgressBar = $this->output->createProgressBar(count($chunkedIds));
        $gettingPlayersProgressBar->start();

        foreach ($chunkedIds as $chunk) {
            $gettingPlayersProgressBar->advance();
            $implodedChunk = implode(',', $chunk);
            $chunkPlayers = Http::get(
                self::PLAYER_API_URL,
                [
                    'ids' => $implodedChunk,
                ]
            )->json()['data'];
            foreach ($chunkPlayers as $player) {
                $playerPosition = $player['position']['id'];
                $players[$playerPosition] ??= [];
                if (array_all($players[$playerPosition], fn ($currentPlayer) => $currentPlayer['playerId'] !== $player['player']['id'])) {
                    $relevantChems =
                        array_map(
                            fn (array $chem) => ['chem' => $chem['displaySlug'], 'count' => $chem['count']],
                            array_filter(
                                $player['availableChemistry'],
                                fn (array $chem) => in_array($chem['externalId'], self::CHEMS)
                            )
                        );
                    sort($relevantChems);
                    $players[$playerPosition][] = [
                        'name' => $player['firstName'].' '.$player['lastName'],
                        'position' => $player['position']['name'] ?? 'Unknown',
                        'ovr' => $player['overall'],
                        'playerId' => $player['player']['id'] ?? null,
                        'positionId' => $playerPosition,
                        'chems' => $relevantChems,
                    ];
                }
            }
        }
        $gettingPlayersProgressBar->finish();

        $resultPlayers = [];

        $sortingPlayersProgressBar = $this->output->createProgressBar(count($players));
        $sortingPlayersProgressBar->start();
        foreach ($players as $positionPlayers) {
            $numPlayersAtPosition = self::POSITIONS[$positionPlayers[0]['positionId']];
            usort($positionPlayers, fn (array $a, array $b) => $b['ovr'] <=> $a['ovr']);
            $positionPlayers = array_slice($positionPlayers, 0, $numPlayersAtPosition);
            $resultPlayers = array_merge($resultPlayers, $positionPlayers);
        }

        $sortingPlayersProgressBar->finish();

        usort($resultPlayers, function ($a, $b) {
            $posComp = $a['positionId'] <=> $b['positionId'];
            $ovrComp = $b['ovr'] <=> $a['ovr'];

            return $posComp !== 0 ? $posComp : $ovrComp;
        });

        $this->drawTableForArray(array_map(
            fn (array $player) => [
                ...$player,
                'chems' => implode(
                    ', ',
                    array_map(
                        fn (array $chem) => strtoupper($chem['chem']).' x'.$chem['count'],
                        $player['chems']
                    )
                ),
            ],
            $resultPlayers
        ));

        $allChems = array_map(
            fn (array $player) => $player['chems'],
            $resultPlayers
        );
        function array_cartesian_product($arrays)
        {
            $result = [];
            $arrays = array_values($arrays);
            $sizeIn = count($arrays);
            $size = $sizeIn > 0 ? 1 : 0;
            foreach ($arrays as $array) {
                $size = $size * count($array);
            }
            for ($i = 0; $i < $size; $i++) {
                $result[$i] = [];
                for ($j = 0; $j < $sizeIn; $j++) {
                    array_push($result[$i], current($arrays[$j]));
                }
                for ($j = ($sizeIn - 1); $j >= 0; $j--) {
                    if (next($arrays[$j])) {
                        break;
                    } elseif (isset($arrays[$j])) {
                        reset($arrays[$j]);
                    }
                }
            }

            return $result;
        }

        $chemCombos = array_unique(
            array_map(
                $this->sumChems(...),
                array_cartesian_product($allChems)
            ),
            SORT_REGULAR
        );
        $chemComboHeaders = array_keys($chemCombos[array_key_first($chemCombos)]);

        usort(
            $chemCombos,
            function (array $a, array $b) {
                $distanceFrom20 = function (array $chem) {
                    $distances = array_map(fn (int $i) => (20 - $i) ** 2, array_filter(array_values($chem)));

                    return array_sum($distances) / count($distances);
                };

                return $distanceFrom20($a) <=> $distanceFrom20($b);
            }
        );

        $csv = implode(',', $chemComboHeaders).PHP_EOL;
        foreach ($chemCombos as $chemCombo) {
            $csv .= implode(',', array_map(fn ($chem) => $chem ?? 0, $chemCombo)).PHP_EOL;
        }
        Storage::put('public/mut-gg-theme-team.csv', $csv);

        return self::SUCCESS;
    }

    private function drawTableForArray(array $array): void
    {
        $this->table(array_keys($array[array_key_first($array)]), $array);
    }

    private function sumChems(array $chems): array
    {
        $returnVal = [];
        foreach ($chems as $chem) {
            [
                'chem' => $chemChem,
                'count' => $chemCount,
            ] = $chem;
            $returnVal[$chemChem] = ($returnVal[$chemChem] ?? 0) + $chemCount;
        }

        return $returnVal;
    }
}
