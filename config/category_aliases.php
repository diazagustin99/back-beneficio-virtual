<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Category Aliases
    |--------------------------------------------------------------------------
    |
    | Distintas billeteras nombran el mismo rubro de formas distintas
    | (singular/plural, sinónimos, con o sin tilde). Cada entrada mapea el
    | slug de la variante al *nombre* de la categoría canónica —
    | ResolvePromotionCategoryAction la consulta antes de crear o buscar la
    | categoría, así nunca se crea un duplicado nuevo por esta vía.
    |
    | Solo se listan pares verificados como la misma cosa (uno de los dos
    | estaba vacío o es un simple plural/sinónimo del otro). Rubros que
    | combinan dos categorías distintas o tienen alcance genuinamente
    | distinto (p. ej. "Jugueterías y Librerías", "Turismo y
    | Entretenimiento") quedan deliberadamente afuera para no perder
    | precisión — fusionarlos reasignaría promociones a una categoría que
    | no las describe bien.
    |
    | `php artisan categories:merge-duplicates` aplica este mismo mapa a
    | las categorías que ya existen en la base (una sola vez, para
    | limpiar el duplicado que un scrape anterior ya haya creado).
    |
    */

    'transportes' => 'Transporte',
    'combustibles' => 'Combustible',
    'supermercado' => 'Supermercados',
    'automoviles' => 'Autos y motos',
    'electrodomesticos-y-tecnologia' => 'Electro y Tecnología',
    'farmacias-y-salud' => 'Farmacias, Perfumerías y Peluquerías',
    'hogar-y-decoracion' => 'Hogar y deco',
    'indumentaria' => 'Indumentaria y Accesorios',
    'jugueterias' => 'Juguetería',
    'mascotas-y-veterinarias' => 'Mascotas',
    'otro' => 'Otros',
    'turismo-y-viajes' => 'Viajes y turismo',

];
