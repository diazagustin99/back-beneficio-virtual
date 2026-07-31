<?php

use App\Scrapers\Brubank\BrubankScraper;
use App\Scrapers\CuentaDni\CuentaDniScraper;
use App\Scrapers\MercadoPago\MercadoPagoScraper;
use App\Scrapers\Modo\ModoScraper;
use App\Scrapers\NaranjaX\NaranjaXScraper;
use App\Scrapers\PersonalPay\PersonalPayScraper;
use App\Scrapers\SemanaNacion\SemanaNacionScraper;
use App\Scrapers\Uala\UalaScraper;

return [

    /*
    |--------------------------------------------------------------------------
    | Wallet Scraper Map
    |--------------------------------------------------------------------------
    |
    | Maps a `wallets.slug` to the WalletScraperInterface implementation that
    | knows how to scrape it. This is the ONLY file that needs a new line to
    | plug in a new wallet — no existing Action, Controller, Job, or Model
    | changes. A wallet without an entry here fails its ScrapeRun with
    | UnregisteredWalletScraperException instead of being scraped.
    |
    */

    'wallets' => [
        'mercado_pago' => MercadoPagoScraper::class,
        'uala' => UalaScraper::class,
        'personal_pay' => PersonalPayScraper::class,
        'naranja_x' => NaranjaXScraper::class,
        'cuenta_dni' => CuentaDniScraper::class,
        'modo' => ModoScraper::class,
        'bna' => SemanaNacionScraper::class,
        'brubank' => BrubankScraper::class,
    ],

];
