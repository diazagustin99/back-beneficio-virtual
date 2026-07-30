<?php

namespace App\Actions\ScrapeRuns;

use App\Models\ScrapeRun;

class ShowScrapeRunAction
{
    public function handle(ScrapeRun $scrapeRun): ScrapeRun
    {
        return $scrapeRun->load('wallet');
    }
}
