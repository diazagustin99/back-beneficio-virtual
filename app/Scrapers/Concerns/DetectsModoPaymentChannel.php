<?php

namespace App\Scrapers\Concerns;

/**
 * Several supermarket feeds (ChangoMás, La Anónima — see
 * plans/0022-wallet-via-modo.md) surface a "MODO" qualifier for a promo
 * that's exclusive to one bank, but only when paid through the MODO app —
 * the same "the underlying bank matters more than the payment channel"
 * idea already captured for MODO's own scraper
 * (`ModoScraper::BANK_WALLET_SLUGS`). *Where* that qualifier lives isn't
 * uniform: ChangoMás bakes it right into the bank label itself ("Banco
 * Credicoop MODO", "Yoy_MODO"), while La Anónima's own bank tile stays
 * bare ("Banco Hipotecario") and only the promo card's own title spells
 * it out ("... con Banco Hipotecario MODO") — so callers pass whichever
 * raw text actually carries it for their own source, not necessarily the
 * bank label. `ResolveWalletFromBankNameAction` already resolves the
 * label to that bank's own wallet via `config/bank_wallet_aliases.php`;
 * this trait adds the one thing that resolution alone loses — that MODO
 * was the channel — back onto the DTO's `paymentMethods`.
 */
trait DetectsModoPaymentChannel
{
    /**
     * @param  string[]  $paymentMethods
     * @return string[]
     */
    protected function addModoChannelIfMentioned(string $rawText, string $resolvedWalletSlug, array $paymentMethods = []): array
    {
        // The promo already lives under the `modo` wallet itself — saying
        // it's "paid via MODO" there would be redundant.
        if ($resolvedWalletSlug === 'modo') {
            return $paymentMethods;
        }

        // Not \b on both sides: several raw labels separate "MODO" with an
        // underscore ("Yoy_MODO", "Hipotecario_Modo"), and underscore
        // counts as a \w character — a plain \bmodo\b never finds a
        // boundary there. Space, underscore, hyphen, or start/end of
        // string all count as a separator instead.
        if (preg_match('/(?:^|[\s_-])modo(?:$|[\s_-])/iu', $rawText) !== 1) {
            return $paymentMethods;
        }

        if (! in_array('MODO', $paymentMethods, true)) {
            $paymentMethods[] = 'MODO';
        }

        return $paymentMethods;
    }
}
