<?php

namespace App\Actions\ScrapeRuns;

use App\Models\ScrapeRun;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ListScrapeRunsAction
{
    /**
     * @param  array{wallet?: string, status?: string, from?: string, to?: string, per_page?: int}  $filters
     */
    public function handle(array $filters = []): LengthAwarePaginator
    {
        return ScrapeRun::query()
            ->with('wallet')
            ->when(
                filled($filters['wallet'] ?? null),
                fn ($query) => $query->whereHas('wallet', fn ($wallet) => $wallet->where('slug', $filters['wallet'])),
            )
            ->when(filled($filters['status'] ?? null), fn ($query) => $query->where('status', $filters['status']))
            ->when(filled($filters['from'] ?? null), fn ($query) => $query->whereDate('created_at', '>=', $filters['from']))
            ->when(filled($filters['to'] ?? null), fn ($query) => $query->whereDate('created_at', '<=', $filters['to']))
            ->latest()
            ->paginate($filters['per_page'] ?? 15);
    }
}
