<?php

namespace App\Http\Controllers;

use App\Console\Commands\MutGGThemeTeam;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

class MutGGThemeTeamController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request)
    {
        Artisan::call(MutGGThemeTeam::class, [
            'teams' => [
                'seattle-seahawks',
                'philadelphia-eagles',
                'new-england-patriots',
            ],
        ]);

        return sprintf('<pre>%s</pre>', Artisan::output());
    }
}
