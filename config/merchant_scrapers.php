<?php

use App\Scrapers\Supermarkets\CarrefourDiscountScraper;
use App\Scrapers\Supermarkets\ChangoMasDiscountScraper;
use App\Scrapers\Supermarkets\JumboDiscountScraper;
use App\Scrapers\Supermarkets\LaAnonimaDiscountScraper;
use App\Scrapers\Supermarkets\VeaDiscountScraper;

return [

    /*
    |--------------------------------------------------------------------------
    | Merchant Scraper Map
    |--------------------------------------------------------------------------
    |
    | Sibling of config/scrapers.php's 'wallets' map, for the pipeline that
    | scrapes a supermarket's own page instead of a wallet's — see
    | MerchantScraperInterface. Maps a `merchants.slug` to the scraper that
    | knows how to scrape it. A merchant without an entry here just isn't
    | part of this pipeline (most merchants aren't — they only ever receive
    | promotions via a wallet's own scrape).
    |
    */

    'merchants' => [
        'carrefour' => CarrefourDiscountScraper::class,
        'la-anonima' => LaAnonimaDiscountScraper::class,
        'vea' => VeaDiscountScraper::class,
        'jumbo' => JumboDiscountScraper::class,
        'changomas' => ChangoMasDiscountScraper::class,
    ],

];
