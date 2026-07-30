<?php

namespace App\Scrapers\Concerns;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

trait MakesHttpRequests
{
    /**
     * A realistic desktop browser User-Agent. Several source sites sit behind
     * a bot-detection layer (Incapsula/Cloudflare) that serves a JS challenge
     * instead of content to clients without one.
     */
    private const string USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

    protected function http(): PendingRequest
    {
        return Http::withUserAgent(self::USER_AGENT)
            ->timeout(15)
            ->connectTimeout(5)
            ->retry(2, 200);
    }
}
