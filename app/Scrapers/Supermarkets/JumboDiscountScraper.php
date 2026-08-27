<?php

namespace App\Scrapers\Supermarkets;

class JumboDiscountScraper extends CencosudBankDiscountScraper
{
    public function merchantName(): string
    {
        return 'Jumbo';
    }

    protected function apiHost(): string
    {
        return 'https://www.jumbo.com.ar';
    }

    protected function websiteKey(): string
    {
        return 'jumboargentinaio';
    }

    protected function sourceUrl(): string
    {
        return 'https://www.jumbo.com.ar/descuentos-del-dia?type=por-dia&day=4';
    }
}
