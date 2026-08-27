<?php

namespace App\Scrapers\Supermarkets;

class VeaDiscountScraper extends CencosudBankDiscountScraper
{
    public function merchantName(): string
    {
        return 'Vea';
    }

    protected function apiHost(): string
    {
        return 'https://www.vea.com.ar';
    }

    protected function websiteKey(): string
    {
        return 'veaargentina';
    }

    protected function sourceUrl(): string
    {
        return 'https://www.vea.com.ar/descuentos-del-dia?type=por-banco';
    }
}
