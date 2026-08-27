<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Bank/Wallet Aliases
    |--------------------------------------------------------------------------
    |
    | A supermarket scraper (see MerchantScraperInterface) resolves the raw
    | bank name off its own page against this map before ever touching
    | `wallets` — same role config/merchant_aliases.php plays for comercios.
    | Each key is the *normalized* (Wallet::normalize(), no accents/spaces/
    | uppercase) raw bank label a real site was confirmed to use; each value
    | is the *canonical name* (not a slug) — ResolveWalletFromBankNameAction
    | looks up an existing wallet by that name's own normalized form (or
    | creates one, if it's genuinely new) exactly like ResolveMerchantAction
    | already does for comercios, so this works identically whether the
    | canonical wallet already exists or doesn't yet.
    |
    | Only names actually seen live are listed — see
    | plans/0021-scrapping-supermercados.md for the Cencosud (Vea/Jumbo) feed
    | this first batch came from.
    |
    */

    'aliases' => [
        // Exact spelling differences against a wallet that already exists.
        'cuentadnibancoprovincia' => 'Cuenta DNI',
        'bancosantander' => 'Santander Río',
        'santander' => 'Santander Río',
        'nacion' => 'Banco Nación',
        'banconacion' => 'Banco Nación',
        'bancomacro' => 'Macro',
        'supervielle' => 'Banco Supervielle',
        'tarjetanaranjax' => 'Naranja X',
        'galiciamodo' => 'Banco Galicia',
        // Confirmed live on Vea's own feed: a bare "Galicia" mention (no
        // "Banco" prefix) — same bank, different label.
        'galicia' => 'Banco Galicia',

        // These 9 already exist too (added this same session for MODO's own
        // bank-attribution — see ModoScraper::BANK_WALLET_SLUGS), spelled
        // differently again on Cencosud's own feed.
        'bancocordoba' => 'BANCOR',
        'bancodeentrerios' => 'Banco Entre Ríos',
        'sanjuan' => 'Banco San Juan',
        'bancodecorrientes' => 'Banco Corrientes',

        // Genuinely new wallets (none of the 25 wallets above cover these) —
        // the canonical name here is what actually creates the wallet the
        // first time any of its variants is seen. "Cencopay Cuenta" and
        // "Banco Patagonia 365" are the same real entity under a second
        // name, confirmed live: a tier/product suffix, same pattern as
        // MODO's own "Macro Selecta".
        'cencopaycuenta' => 'CencoPay',
        'bancopatagonia365' => 'Banco Patagonia',

        // ChangoMás's own feed confirmed live: the same "MODO" qualifier-
        // suffix pattern already seen on Supervielle's merchant names (see
        // Merchant::stripModoSuffix()), except here it's the *bank* label
        // itself that carries it, sometimes separated by an underscore
        // instead of a space — "Banco Credicoop MODO", "ICBC Modo",
        // "Hipotecario_Modo", "Yoy_MODO", "Banco_Comafi_MODO" are the same
        // real banks, not a distinct "paid via MODO" wallet. "ICBC_Sueldos"
        // is the same idea for a salary-account customer segment.
        'bancocredicoopmodo' => 'Banco Credicoop',
        'icbcmodo' => 'ICBC',
        'icbcsueldos' => 'ICBC',
        'hipotecariomodo' => 'Banco Hipotecario',
        'yoymodo' => 'YOY',
        'bancocomafimodo' => 'Banco Comafi',

        // Same product-tier suffix as "Banco Patagonia 365" above, just
        // spelled without the "Banco" prefix on ChangoMás's own feed.
        'patagonia365' => 'Banco Patagonia',

        // "BNA" is just the common abbreviation for Banco de la Nación
        // Argentina — redundant with "Banco Nación" already above.
        'banconacionbna' => 'Banco Nación',

        // Resolves the "banco-provincia vs cuenta_dni" ambiguity flagged in
        // plans/0021-scrapping-supermercados.md — for THIS specific compound
        // label, ChangoMás's own feed states the two are the same thing
        // ("Banco Provincia - Cuenta DNI"). A bare "Banco Provincia" mention
        // (no "Cuenta DNI" alongside it) stays deliberately unmapped until
        // seen live on its own.
        'bancoprovinciacuentadni' => 'Cuenta DNI',

        // "Sol" alone looks generic, but ChangoMás's own bank-catalog tile
        // for it is filed under the image "LogoId Banco Sol.png" — real
        // evidence it names a distinct bank, not a stray word. La Anónima
        // lists the same bank under its fuller name, "Banco Del Sol" (and,
        // separately, its own "Tarjeta Del Sol" — see `skip` below), so
        // that fuller name is the canonical target both converge on.
        'sol' => 'Banco Del Sol',

        // La Anónima's own bank filter, confirmed live: the same banks
        // above spelled with a "Banco"/qualifier variation again.
        'bancomercadopago' => 'Mercado Pago',
        'bancoicbc' => 'ICBC',
        'bancomodo' => 'MODO',
        'bancosanjuanmodo' => 'Banco San Juan',
    ],

    /*
    |--------------------------------------------------------------------------
    | Non-Bank Labels
    |--------------------------------------------------------------------------
    |
    | A supermarket's own "bank" field sometimes isn't a bank at all — a
    | payment network (Visa/Mastercard/Amex), a government installment
    | program ("Plan Ahora 3"), a generic catch-all ("Medios de Pago" —
    | "means of payment"), the supermarket's own parent company
    | ("Cencosud"), or a co-branded loyalty program that isn't a wallet a
    | user follows in this app's sense ("Jumbo Mas Clarin"). Creating a real
    | wallet for any of these would be exactly the kind of garbage the
    | "verify before creating" discipline in this project exists to avoid —
    | ResolveWalletFromBankNameAction returns null for these instead, and
    | the scraper simply drops that one discount.
    |
    | Normalized the same way as the aliases above. Only labels actually
    | seen live are listed; a name not in either list still creates a wallet
    | (the point of the "en caso de no existir, crearlas" rule) — this list
    | is the deliberate, narrow exception to that, not a general filter.
    |
    */

    'skip' => [
        'mediosdepago',
        'visaymaster',
        'visa',
        'mastercard',
        'amex',
        'americanexpress',
        '3csi',
        'planahora3',
        'cencosud',
        'move',
        'tarjetasol',
        'tarjetamarcojuarez',
        'jumbomasclarin',
        'jumbomaspersonal',

        // ChangoMás's own feed, confirmed live: a generic catch-all ("any
        // digital wallet"), the supermarket's own loyalty club (self-
        // referential, same idea as "cencosud" above), Argentina's
        // social-security agency and a public-sector customer segment
        // (neither is a bank), and a single, too-generic mention with no
        // identifying context to safely create a wallet for.
        'billeterasvirtuales',
        'masclub',
        'anses',
        'empleadospublicos',

        // La Anónima's own bank filter, confirmed live: several "Tarjeta
        // X" tiles that are co-branded card products, not their own bank
        // (already the same idea as "tarjetasol" above), plus one generic
        // "any single card" catch-all and one unfamiliar acronym with no
        // identifying context ("TLA Exclusivo Plus").
        'tarjetadelsol',
        'tlaexclusivoplus',
        'tarjetasucredito',
        'tarjetatitanio',
        'tarjetaunica',
    ],

];
