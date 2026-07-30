<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\ScrapeRuns\ListScrapeRunsAction;
use App\Actions\ScrapeRuns\ShowScrapeRunAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ListScrapeRunsRequest;
use App\Http\Resources\Api\V1\ScrapeRunResource;
use App\Models\ScrapeRun;
use Throwable;

class ScrapeRunController extends Controller
{
    public function index(ListScrapeRunsRequest $request, ListScrapeRunsAction $action)
    {
        try {
            $scrapeRuns = $action->handle($request->validated());

            return $this->response($scrapeRuns->through(fn (ScrapeRun $scrapeRun) => new ScrapeRunResource($scrapeRun)));
        } catch (Throwable $e) {
            return $this->response($e, 500, 'Error al listar las corridas de scraping');
        }
    }

    public function show(ScrapeRun $scrapeRun, ShowScrapeRunAction $action)
    {
        try {
            return $this->response(new ScrapeRunResource($action->handle($scrapeRun)));
        } catch (Throwable $e) {
            return $this->response($e, 500, 'Error al obtener la corrida de scraping');
        }
    }
}
