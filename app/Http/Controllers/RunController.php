<?php

namespace App\Http\Controllers;

use App\Console\Commands\MutGGThemeTeam;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

class RunController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request)
    {
        $selectedCommand = $request->get('selectedCommand', false);
        $artisanOutput = null;
        if ($selectedCommand) {
            if (in_array($selectedCommand, array_keys($this->getCommands()))) {
                $args = array_merge(
                    $request->get('args', []),
                    [
                        '--no-interaction' => true,
                    ]
                );
                Artisan::call($selectedCommand, $args);
                $artisanOutput = Artisan::output();
            } else {
                throw new AuthorizationException("Command \"{$selectedCommand}\" not found in allowed list.");
            }
        }

        $commands = $this->getCommands();

        return inertia(
            'Runner/Run',
            [
                'commands' => $commands,
                'artisanOutput' => $artisanOutput,
            ]
        );
    }

    private function getCommands(): array
    {
        return [
            MutGGThemeTeam::class => [
                'teams' => [
                    'type' => 'select',
                    'multiple' => true,
                    'options' => MutGGThemeTeam::getTeams(),
                ],
            ],
        ];
    }
}
