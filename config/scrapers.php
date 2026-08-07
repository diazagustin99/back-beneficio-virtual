<?php

use App\Scrapers\BancoCiudad\BancoCiudadScraper;
use App\Scrapers\Brubank\BrubankScraper;
use App\Scrapers\Credicoop\CredicoopScraper;
use App\Scrapers\CuentaDni\CuentaDniScraper;
use App\Scrapers\Galicia\GaliciaScraper;
use App\Scrapers\Icbc\IcbcScraper;
use App\Scrapers\Macro\MacroScraper;
use App\Scrapers\MercadoPago\MercadoPagoScraper;
use App\Scrapers\Modo\ModoScraper;
use App\Scrapers\NaranjaX\NaranjaXScraper;
use App\Scrapers\PersonalPay\PersonalPayScraper;
use App\Scrapers\Prex\PrexScraper;
use App\Scrapers\Santander\SantanderScraper;
use App\Scrapers\SemanaNacion\SemanaNacionScraper;
use App\Scrapers\Supervielle\SupervielleScraper;
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
    | changes.
    |
    | A wallet without an entry here is legitimate — it's an
    | attribution-only wallet, one that never has its own scrape but can
    | still receive promotions another scraper confirms belong to it (e.g.
    | ModoScraper attributing a bank-exclusive MODO promo directly to that
    | bank's own wallet; see BANK_WALLET_SLUGS there). `WalletScraperRegistry`
    | ->has() is how `DispatchDailyScrapesAction` recognizes this and skips
    | scheduling a scrape that's guaranteed to fail with
    | UnregisteredWalletScraperException.
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
        'macro' => MacroScraper::class,
        'prex' => PrexScraper::class,
        'icbc' => IcbcScraper::class,
        'galicia' => GaliciaScraper::class,
        'santander' => SantanderScraper::class,
        'credicoop' => CredicoopScraper::class,
        'banco_ciudad' => BancoCiudadScraper::class,
        'supervielle' => SupervielleScraper::class,
    ],

];
