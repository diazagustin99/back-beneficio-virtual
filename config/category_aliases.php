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
    'indumentaria' => 'Moda y accesorios',
    'jugueterias' => 'Juguetería',
    'mascotas-y-veterinarias' => 'Mascotas',
    'otro' => 'Otros',
    'turismo-y-viajes' => 'Viajes y turismo',

    // ICBC expone su `rubro` siempre en mayúsculas y con nombres abreviados
    // (p. ej. "SUPER", "RESTO") — estos son los que resultaron ser la misma
    // categoría que una ya existente, verificados igual que los de arriba.
    'super' => 'Supermercados',
    'moda' => 'Moda y accesorios',
    'libreria' => 'Librerías',
    'otros-beneficios' => 'Otros',
    'resto' => 'Gastronomía',
    'casa' => 'Hogar y deco',
    'capacitacion' => 'Educación',
    'belleza' => 'Salud y Belleza',
    'auto' => 'Autos y motos',
    'turismo' => 'Viajes y turismo',

    // Galicia/Santander/Credicoop/Banco Ciudad/Supervielle (agregadas en el
    // mismo scrape) volvieron a traer variantes de rubros ya existentes —
    // verificadas de la misma forma: mirando una promoción real de cada
    // categoría para confirmar que es el mismo rubro, no solo una palabra
    // parecida.
    'automotor' => 'Autos y motos',
    'automoviles-y-motos' => 'Autos y motos',
    'hogar' => 'Hogar y deco',
    'farmacia' => 'Farmacias',
    'deporte' => 'Deportes',
    // Confirmado con una promoción real de Santander: "Novecento Cañitas"
    // (restaurante) categorizado como "Dining" — es Gastronomía, en inglés.
    'dining' => 'Gastronomía',

    // Lote verificado con una muestra real de comercio+promoción de cada
    // una (plans/0025-categorias-duplicadas.md) — nunca por parecido de
    // nombre solo.
    'automotor-y-combustible' => 'Autos y motos',
    'vehiculos' => 'Autos y motos',
    // Pirelli/Ducati (motos), no bicicletas — a pesar del nombre.
    'rodados' => 'Autos y motos',
    'construccion-y-hogar' => 'Construcción',
    'ferreteria-y-pinturerias' => 'Construcción',
    'pinturerias' => 'Construcción',
    'bares' => 'Gastronomía',
    'confiteria' => 'Gastronomía',
    'fast-food' => 'Gastronomía',
    'restaurantes' => 'Gastronomía',
    'electro' => 'Electro y Tecnología',
    'electronica' => 'Electro y Tecnología',
    'tecnologia' => 'Electro y Tecnología',
    'farmacias' => 'Farmacias, Perfumerías y Peluquerías',
    'perfumerias' => 'Farmacias, Perfumerías y Peluquerías',
    'cuidado-personal' => 'Farmacias, Perfumerías y Peluquerías',
    // CityKids y "Compañía de Juguetes" aparecen tanto en "Juguetería"
    // como en "Niños" — mismos comercios, mismo rubro.
    'juguetes' => 'Juguetería',
    'ninos' => 'Juguetería',
    'infantiles' => 'Juguetería',
    'dia-del-nino' => 'Juguetería',
    // Una promoción real dice literalmente "Supers de Santa Fe".
    'mercados' => 'Supermercados',
    'espectaculos' => 'Entretenimiento',
    'vinos-y-bodegas' => 'Bodegas',
    'varios' => 'Otros',
    'estaciones-de-servicios' => 'Combustible',
    // "Moda"/"Indumentaria" son sinónimos en español, "Accesorios" es
    // idéntico en las dos — el grupo de mayor impacto (~4700 promos
    // sumadas). "Indumentaria y Accesorios" queda como variante, no
    // canónica: "Moda y accesorios" ya tenía casi el doble de promos.
    'indumentaria-y-accesorios' => 'Moda y accesorios',
    // El mismo rubro partido por provincia — igual patrón que las "AHORA
    // X" de Macro (plan 0023). "Carnicerías" es nueva, no existía.
    'carnicerias-mendoza' => 'Carnicerías',
    'carnicerias-san-luis' => 'Carnicerías',

];
