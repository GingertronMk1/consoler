<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Symfony\Component\DomCrawler\Crawler;

use function Laravel\Prompts\multiselect;

class MutGGThemeTeam extends Command
{
    private const bool OUTPUT_CSV = false;

    private const string PLAYER_BASE_URL = 'https://www.mut.gg/players/';

    private const string API_URL = 'https://www.mut.gg/api/25';

    private const string PLAYER_API_URL = self::API_URL.'/player-items';

    public const array CHEMS = [
        'arizona-cardinals' => 6070,
        'atlanta-falcons' => 6140,
        'baltimore-ravens' => 6250,
        'buffalo-bills' => 6030,
        'carolina-panthers' => 6210,
        'chicago-bears' => 6010,
        'cincinnati-bengals' => 6020,
        'cleveland-browns' => 6050,
        'dallas-cowboys' => 6110,
        'denver-broncos' => 6040,
        'detroit-lions' => 6190,
        'green-bay-packers' => 6200,
        'houston-texans' => 6320,
        'indianapolis-colts' => 6100,
        'jacksonville-jaguars' => 6170,
        'kansas-city-chiefs' => 6090,
        'las-vegas-raiders' => 6230,
        'los-angeles-chargers' => 6080,
        'los-angeles-rams' => 6240,
        'miami-dolphins' => 6120,
        'minnesota-vikings' => 6310,
        'new-england-patriots' => 6220,
        'new-orleans-saints' => 6270,
        'new-york-giants' => 6160,
        'new-york-jets' => 6180,
        'philadelphia-eagles' => 6130,
        'pittsburgh-steelers' => 6290,
        'san-francisco-49ers' => 6150,
        'seattle-seahawks' => 6280,
        'tampa-bay-buccaneers' => 6060,
        'tennessee-titans' => 6300,
        'washington-commanders' => 6260,
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
    protected $signature = 'app:mut-g-g-theme-team {--C|core-data} {teams?*}';

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
        if ($this->option('core-data') === true) {
            $this->info('Getting core data');
            $coreData = Http::get(self::API_URL.'/core-data')->json()['data'];
            $chemDefs = [];
            foreach ($coreData['chemistryDefs'] as $_chemistryDef) {
                if ($_chemistryDef['chemistryType'] === 1) {
                    $chemDefs[$_chemistryDef['themeTeamSlug']] = $_chemistryDef['externalId'];
                }
            }

            ksort($chemDefs);

            $this->line(var_export($chemDefs, true));

            return 1;
        }

        $teams = $this->argument('teams');

        while (empty($teams)) {
            $teams = multiselect(
                label: 'What teams do you want?',
                options: array_keys(self::CHEMS),
            );

            $this->getOutput()->listing($teams);
        }

        $pages = [];
        foreach ($teams as $slug) {
            $chemId = self::CHEMS[$slug];
            foreach (array_keys(self::POSITIONS) as $position) {
                for ($page = 1; $page < 2; $page++) {
                    $pages[] = [
                        'chemistry' => "{$chemId}-1",
                        'teamName' => $slug,
                        'page' => $page,
                        'positions' => $position,
                    ];

                }
            }
        }

        $gettingIdsProgressBar = $this->output->createProgressBar(count($pages));

        $playerIds = [];

        $this->info('Getting player IDs');
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
        $this->newLine();

        $players = [];

        $chunkedIds = array_chunk($playerIds, 10);

        $gettingPlayersProgressBar = $this->output->createProgressBar(count($chunkedIds));
        $this->info('Getting players from API');
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
                            fn (array $chem) => ['chem' => $chem['displaySlug'], 'count' => $chem['count'] ?? 1],
                            array_filter(
                                $player['availableChemistry'],
                                fn (array $chem) => in_array($chem['themeTeamSlug'], $teams)
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
                        'programId' => $player['program']['id'] ?? null,
                        'programName' => $player['program']['name'] ?? null,
                    ];
                }
            }
        }
        $gettingPlayersProgressBar->finish();
        $this->newLine();

        $resultPlayers = [];

        $sortingPlayersProgressBar = $this->output->createProgressBar(count($players));
        $this->info('Sorting players');
        $sortingPlayersProgressBar->start();
        foreach ($players as $positionPlayers) {
            $numPlayersAtPosition = self::POSITIONS[$positionPlayers[0]['positionId']];
            usort($positionPlayers, fn (array $a, array $b) => $b['ovr'] <=> $a['ovr']);
            $positionPlayers = array_slice($positionPlayers, 0, $numPlayersAtPosition);
            foreach ($positionPlayers as $positionPlayer) {
                $resultPlayers[] = $this->expandPositionPlayer($positionPlayer);
            }
        }

        $sortingPlayersProgressBar->finish();
        $this->newLine();

        $chemCombos = array_unique(
            $this->getChemCombos($resultPlayers),
            SORT_REGULAR
        );

        usort(
            $chemCombos,
            $this->sortChemCombos(...)
        );

        $bestCombo = array_shift($chemCombos);

        usort(
            $bestCombo,
            function (array $playerA, array $playerB) {
                $positionId = $playerA['positionId'] <=> $playerB['positionId'];
                if ($positionId !== 0) {
                    return $positionId;
                }

                return $playerB['ovr'] <=> $playerA['ovr'];
            }
        );

        $this->drawTableForArray(
            array_map(
                function (array $player) {
                    $ret = $player;
                    $ret['chem'] = sprintf('%s x%d', strtoupper($player['chem']['chem']), $player['chem']['count']);

                    return $ret;
                },
                $bestCombo
            ),
            [
                'playerId',
                'positionId',
                'programId',
            ]
        );

        $bestComboChems = [];
        foreach ($bestCombo as $player) {
            [
                'chem' => $chemChem,
                'count' => $chemCount,
            ] = $player['chem'];
            $currentCount = $bestComboChems[$chemChem] ?? 0;
            $bestComboChems[$player['chem']['chem']] = $currentCount + $chemCount;
        }

        $bestComboChemsListing = [];
        foreach ($bestComboChems as $chem => $count) {
            $bestComboChemsListing[] = ['chem' => $chem, 'count' => $count];
        }

        $this->drawTableForArray($bestComboChemsListing);

        return self::SUCCESS;
    }

    private function drawTableForArray(array $array, array $ignoreKeys = []): void
    {
        $keys = array_keys($array[array_key_first($array)]);
        $keys = array_diff($keys, $ignoreKeys);
        $tableVals = array_map(
            function (array $item) use ($keys) {
                $newItem = [];
                foreach ($keys as $key) {
                    $newItem[$key] = $item[$key] ?? null;
                }

                return $newItem;
            },
            $array
        );
        $this->table($keys, $tableVals);
    }

    private function getChemCombos(array $arrays): array
    {
        $result = [];
        $arrays = array_values($arrays);
        $sizeIn = count($arrays);
        $size = $sizeIn > 0 ? 1 : 0;
        foreach ($arrays as $array) {
            $size = $size * count($array);
        }
        $progressBar = $this->output->createProgressBar($size);
        $this->info('Getting all combinations of chemistries');
        $progressBar->start();
        for ($i = 0; $i < $size; $i++) {
            $result[$i] = [];
            for ($j = 0; $j < $sizeIn; $j++) {
                $result[$i][] = current($arrays[$j]);
            }
            for ($j = ($sizeIn - 1); $j >= 0; $j--) {
                if (next($arrays[$j])) {
                    break;
                } elseif (isset($arrays[$j])) {
                    reset($arrays[$j]);
                }
            }
            $progressBar->advance();
        }
        $progressBar->finish();
        $this->newLine();

        return $result;
    }

    private function sortChemCombos(array $a, array $b): int
    {
        $distanceFrom20 = function (array $players) {
            $chem = [];
            foreach ($players as $player) {
                [
                    'chem' => $chemChem,
                    'count' => $chemCount,
                ] = $player['chem'];
                $chem[$chemChem] = ($chem[$chemChem] ?? 0) + $chemCount;
            }

            $distances = array_map(fn (int $i) => (20 - $i) ** 2, array_filter(array_values($chem)));

            return array_sum($distances) / count($distances);
        };

        return $distanceFrom20($a) <=> $distanceFrom20($b);
    }

    private function expandPositionPlayer(array $player): array
    {
        $returnVal = [];
        foreach ($player['chems'] as $chem) {
            $chemmedPlayer = [
                ...$player,
                'chem' => $chem,
            ];
            unset($chemmedPlayer['chems']);
            $returnVal[] = $chemmedPlayer;
        }

        return $returnVal;
    }

    public static function getTeams(): array
    {
        return array_keys(self::CHEMS);
    }
}
