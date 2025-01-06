<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Symfony\Component\DomCrawler\Crawler;

class MutGGThemeTeam extends Command
{
    private const string PLAYER_BASE_URL = 'https://www.mut.gg/players/';
    private const string API_URL = 'https://www.mut.gg/api/25';
    private const string PLAYER_API_URL = self::API_URL . '/player-items';

    private const array CHEMS = [
        'seahawks' => '6280',
        'eagles' => '6130',
        'patriots' => '6220',
        'chiefs' => '6090',
    ];

    private const array POSITIONS = [
        1 => 'QB',
        2 => 'HB',
        3 => 'FB',
        4 => 'WR',
        5 => 'TE',
        6 => 'LT',
        7 => 'LG',
        8 => 'C',
        9 => 'RG',
        10 => 'RT',
        11 => 'LE',
        12 => 'RE',
        13 => 'DT',
        14 => 'LOLB',
        15 => 'MLB',
        16 => 'ROLB',
        17 => 'CB',
        18 => 'FS',
        19 => 'SS',
        20 => 'K',
        21 => 'P',
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

        $coreData = Http::get(self::API_URL . '/core-data')->json()['data'];
        $pages = [];
        foreach (self::CHEMS as $teamName => $chemId) {
            foreach (self::POSITIONS as $position => $positionName) {
                for ($page = 1; $page < 2; $page++) {
                    $pages[] = [
                        'chemistry' => "{$chemId}-1",
                        'teamName' => $teamName,
                        'page' => $page,
                        'positions' => $position,
                        'positionName' => $positionName,
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
            $crawler->each(function (Crawler $node) use (&$playerIds, $page) {
                $playerId = $node->attr('data-external-id');
                $playerIds[] = $playerId;
            });
        };
        $gettingIdsProgressBar->finish();


        $players = [];

        $chunkedIds = array_chunk($playerIds, 10);

        $gettingPlayersProgressBar = $this->output->createProgressBar(count($chunkedIds));
        $gettingPlayersProgressBar->start();

        foreach($chunkedIds as $chunk) {
            $gettingPlayersProgressBar->advance();
            $implodedChunk = implode(',', $chunk);
            $chunkPlayers =  Http::get(
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
                            fn (array $chem) => strtoupper($chem['displaySlug']) . ' x' . $chem['count'],
                            array_filter(
                                $player['availableChemistry'],
                                fn (array $chem) => in_array($chem['externalId'], self::CHEMS)
                            )
                        );
                    sort($relevantChems);
                    $players[$playerPosition][] = [
                        'name' => $player['firstName'] . ' ' . $player['lastName'],
                        'position' => $player['position']['name'] ?? 'Unknown',
                        'ovr' => $player['overall'],
                        'playerId' => $player['player']['id'] ?? null,
                        'positionId' => $playerPosition,
                        'chems' => implode(
                            ', ',
                            $relevantChems
                        )
                    ];
                }
            }
        }
        $gettingPlayersProgressBar->finish();

        $resultPlayers = [];

        $sortingPlayersProgressBar = $this->output->createProgressBar(count($players));
        $sortingPlayersProgressBar->start();
        foreach ($players as $positionPlayers) {
            $positionPlayers = array_slice($positionPlayers, 0, 5);
            $resultPlayers = array_merge($resultPlayers, $positionPlayers);
        }

        $sortingPlayersProgressBar->finish();

        usort($resultPlayers, function ($a, $b) {
            $posComp = $a['positionId'] <=> $b['positionId'];
            $ovrComp = $b['ovr'] <=> $a['ovr'];
            return $posComp !== 0 ? $posComp : $ovrComp;
        });


        $this->table(array_keys($resultPlayers[0]), $resultPlayers);

        return self::SUCCESS;
    }
}
