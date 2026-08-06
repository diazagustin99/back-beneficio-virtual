<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Merchant Aliases
    |--------------------------------------------------------------------------
    |
    | Distintas billeteras nombran el mismo comercio de formas distintas
    | (abreviado, con errores de tipeo del origen, etc). Cada entrada mapea
    | el nombre normalizado (Merchant::normalize(), sin acentos/espacios/
    | mayúsculas) de la variante al *nombre* del comercio canónico —
    | ResolveMerchantAction la consulta antes de buscar por nombre exacto o
    | de crear un comercio nuevo, así nunca se crea un duplicado nuevo por
    | esta vía.
    |
    | Solo se listan pares verificados como el mismo comercio real (mismas
    | promociones/rubro), nunca por parecido superficial de nombre.
    |
    | `php artisan merchants:merge-duplicates` aplica este mismo mapa a los
    | comercios que ya existen en la base (para limpiar el duplicado que un
    | scrape anterior ya haya creado).
    |
    */

    // Brubank abrevia el nombre ("Aerolineas Arg"); MODO lo abrevia distinto
    // y le agrega su propio nombre ("Aerolineas Arg Modo") — verificado
    // contra el comercio ya existente "Aerolíneas Argentinas" (mismas
    // promociones de vuelos/Aerolíneas Plus).
    'aerolineasarg' => 'Aerolíneas Argentinas',
    'aerolineasargmodo' => 'Aerolíneas Argentinas',

    // Cada billetera nombra al supermercado a su manera (orden de palabras
    // invertido, mayúsculas, o solo el apellido) — verificado contra el
    // comercio ya existente "Aiello Supermercados S. A" (misma categoría
    // Supermercados en las 4 variantes). "Aiello Morrone Decoraciones"
    // queda deliberadamente afuera: es un negocio de decoración, no el
    // supermercado, y no tiene ninguna promoción que lo confirme.
    'supermercadosaiello' => 'Aiello Supermercados S. A',
    'aiello' => 'Aiello Supermercados S. A',
    'aiellosupermercados' => 'Aiello Supermercados S. A',

    // "Alvear" es un nombre de calle muy común, así que NO se agrega como
    // alias genérico — solo estos 2 nombres puntuales, verificados contra
    // el comercio ya existente "Alvear Supermercados" (categoría
    // Supermercados/Mercados en MODO, Naranja X y BNA). Los demás comercios
    // que contienen "Alvear" en la base (farmacias, ópticas, colchonerías,
    // el Jockey Club, etc.) son negocios distintos que solo comparten esa
    // palabra y quedan deliberadamente afuera.
    'supermercadosalvear' => 'Alvear Supermercados',
    'alvear' => 'Alvear Supermercados',

    // Verificado contra el comercio ya existente "Angler Sa": la promoción
    // de Naranja X ("Hasta 10 cuotas cero interés") es idéntica en ambas
    // variantes, solo que en una quedó mal categorizada como "Supermercados"
    // en vez de "Construcción"/"Hogar y deco". "Wrangler" (indumentaria) es
    // un comercio distinto que solo comparte la subcadena y no se toca.
    'angler' => 'Angler Sa',

    // Verificado contra el comercio ya existente "Andres Merino Pinturerias"
    // (categorías Construcción/Hogar y deco): la promoción de ICBC
    // ("hasta 6 cuotas sin interés") es la misma que la de Macro, solo que
    // esta última quedó sin categorizar ("Otros"). Nombre propio poco común,
    // no hay riesgo de colisión con otro comercio real.
    'andresmerino' => 'Andres Merino Pinturerias',

    // "Arco Iris" también es una frase genérica común (usada por pinturerías,
    // jugueterías, cotillones, un shopping, etc. sin relación entre sí), pero
    // estas variantes puntuales quedaron verificadas contra el comercio ya
    // existente "Arcoiris Supermercados": todas caen en categoría
    // Supermercados/Mercados, y la promoción de Naranja X ("Hasta 6 cuotas
    // cero interés") se repite igual en varias de ellas (aparentemente cada
    // sucursal/razón social quedó como una fila distinta). Los comercios de
    // pinturería, jugueterías, cotillón, shopping y "de Luz" quedan afuera:
    // son negocios distintos que solo comparten la frase "Arco Iris".
    'supermercadosarcoiris' => 'Arcoiris Supermercados',
    'supermercadoarcoiris' => 'Arcoiris Supermercados',
    'arcoiris' => 'Arcoiris Supermercados',
    'arcoirissupersucbaigorria' => 'Arcoiris Supermercados',
    'arcoirissupersucbaigorriaelectro' => 'Arcoiris Supermercados',
    'arcoirisgroup' => 'Arcoiris Supermercados',

];
