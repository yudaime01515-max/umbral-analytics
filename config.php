<?php
$envKey = getenv('API_SPORTS_KEY');          // clave de API-Sports (desde GitHub)
$fdKey  = getenv('FOOTBALL_DATA_KEY');       // clave de football-data.org (desde GitHub)
$apiSportsPaid = getenv('API_SPORTS_PAID') === '1'; // pon API_SPORTS_PAID=1 si pagas el plan Pro
$bdlKey = getenv('BALLDONTLIE_KEY');         // clave gratis de BallDontLie (NBA, desde GitHub)
/**
 * Umbral Analytics — configuración
 * Es el único archivo que necesitas editar.
 */
return [

    // Nombre que aparece en la web (puedes cambiarlo cuando quieras)
    'brand'        => 'Umbral Analytics',
    'brand_short'  => 'Umbral',
    'contact'      => 'contacto@tudominio.com',

    // Clave de API-Sports (https://dashboard.api-football.com). Una sola cuenta sirve para todos los deportes.
    'api_key'      => $envKey ?: 'PEGA_AQUI_TU_API_KEY',

    // Clave de football-data.org (gratis, solo fútbol, 12 competiciones de la temporada actual)
    'football_data_key' => $fdKey ?: '',

    // Clave de BallDontLie (opcional; la NBA usa ESPN, que no pide clave)
    'balldontlie_key' => $bdlKey ?: '',

    // Modo prueba: true = usa datos simulados de la carpeta mock/ (no gasta consultas). Pon false para datos reales.
    // (Si la clave viene de GitHub, el modo prueba se apaga solo)
    'mock'         => ($envKey || $fdKey || $bdlKey) ? false : true,

    // Zona horaria de la web
    'timezone'     => 'America/Lima',

    // Probabilidad mínima para publicar un pronóstico y guardarlo en el historial (0.70 = 70 %)
    'threshold'    => 0.70,

    // Días hacia adelante que se muestran (0 = solo hoy)
    'days_ahead'   => 3,

    // Minutos que se guarda cada respuesta de la API antes de volver a pedirla (ahorra consultas)
    'cache_minutes'=> 180,

    // Clave para ejecutar update.php desde el navegador (solo si tu hosting no tiene cron). Cámbiala.
    'cron_token'   => 'cambia-esta-clave-larga-123',

    // Margen estimado de las casas de apuestas, usado para calcular el retorno del historial
    'bookmaker_margin' => 0.06,

    /*
     * Deportes y ligas.
     * Los ID de liga y el formato de temporada se comprueban en el dashboard de API-Sports
     * (sección de cada deporte → Leagues). Activa o desactiva cada liga con 'on'.
     */
    'sports' => [
        'futbol' => [
            'name' => 'Fútbol', 'host' => 'https://v3.football.api-sports.io', 'endpoint' => 'fixtures',
            'model' => 'poisson_draw',
            // Con clave de football-data.org el fútbol sale de ahí (gratis); si no, de API-Sports
            'players' => true, // estadísticas de jugadores (ESPN, solo con football-data)
            'source' => $fdKey ? 'footballdata' : 'apisports',
            'leagues' => $fdKey ? [
                ['code' => 'PL',  'name' => 'Premier League',   'on' => true],
                ['code' => 'PD',  'name' => 'LaLiga',           'on' => true],
                ['code' => 'SA',  'name' => 'Serie A',          'on' => true],
                ['code' => 'BL1', 'name' => 'Bundesliga',       'on' => true],
                ['code' => 'FL1', 'name' => 'Ligue 1',          'on' => true],
                ['code' => 'CL',  'name' => 'Champions League', 'on' => true],
                ['code' => 'PPL', 'name' => 'Primeira Liga',    'on' => true],
                ['code' => 'DED', 'name' => 'Eredivisie',       'on' => true],
            ] : [
                ['id' => 281, 'season' => 2026, 'name' => 'Liga 1 Perú',     'on' => true],
                ['id' => 39,  'season' => 2026, 'name' => 'Premier League',  'on' => true],
                ['id' => 140, 'season' => 2026, 'name' => 'LaLiga',          'on' => true],
                ['id' => 135, 'season' => 2026, 'name' => 'Serie A',         'on' => true],
                ['id' => 78,  'season' => 2026, 'name' => 'Bundesliga',      'on' => true],
                ['id' => 61,  'season' => 2026, 'name' => 'Ligue 1',         'on' => false],
                ['id' => 2,   'season' => 2026, 'name' => 'Champions League','on' => false],
            ],
        ],
        'basquet' => [
            // NBA: con datos reales sale de ESPN (gratis, sin clave). Con API_SPORTS_PAID=1 usa API-Sports.
            'enabled' => true,
            'source' => $apiSportsPaid ? 'apisports' : (($envKey || $fdKey || $bdlKey) ? (getenv('NBA_SOURCE') ?: 'espn') : 'apisports'),
            'players' => true, // estadísticas de jugadores (solo con ESPN)
            'name' => 'Básquet', 'host' => 'https://v1.basketball.api-sports.io', 'endpoint' => 'games',
            'model' => 'normal', 'sd' => 12.5, 'tsd' => 17, 'alt' => 7, 'talt' => 13, 'home' => 2.5,
            'leagues' => ($apiSportsPaid || !($envKey || $fdKey || $bdlKey)) ? [
                ['id' => 12, 'season' => '2026-2027', 'name' => 'NBA', 'on' => true],
            ] : [
                // 'season' => 'auto' = temporada en curso (la que empieza en octubre)
                ['code' => 'NBA', 'season' => 'auto', 'name' => 'NBA', 'on' => true],
            ],
        ],
        'beisbol' => [
            'enabled' => !$fdKey || $apiSportsPaid,
            'name' => 'Béisbol', 'host' => 'https://v1.baseball.api-sports.io', 'endpoint' => 'games',
            'model' => 'poisson_ot', 'unit' => 'carreras', 'alt' => 2,
            'leagues' => [
                ['id' => 1, 'season' => 2026, 'name' => 'MLB', 'on' => true],
            ],
        ],
        'nfl' => [
            'enabled' => !$fdKey || $apiSportsPaid,
            'name' => 'Fútbol americano', 'host' => 'https://v1.american-football.api-sports.io', 'endpoint' => 'games',
            'model' => 'normal', 'sd' => 13.5, 'tsd' => 10, 'alt' => 7, 'talt' => 9, 'home' => 2,
            'leagues' => [
                ['id' => 1, 'season' => 2026, 'name' => 'NFL', 'on' => true],
            ],
        ],
        'hockey' => [
            'enabled' => !$fdKey || $apiSportsPaid,
            'name' => 'Hockey', 'host' => 'https://v1.hockey.api-sports.io', 'endpoint' => 'games',
            'model' => 'poisson_ot', 'unit' => 'goles', 'alt' => 1,
            'leagues' => [
                ['id' => 57, 'season' => 2026, 'name' => 'NHL', 'on' => true],
            ],
        ],
        'voley' => [
            'enabled' => !$fdKey || $apiSportsPaid,
            'name' => 'Vóley', 'host' => 'https://v1.volleyball.api-sports.io', 'endpoint' => 'games',
            'model' => 'sets5',
            'leagues' => [
                // Busca el ID de la liga peruana u otra en el dashboard y actívala
                ['id' => 0, 'season' => 2026, 'name' => 'Liga de vóley', 'on' => false],
            ],
        ],
        'balonmano' => [
            'enabled' => !$fdKey || $apiSportsPaid,
            'name' => 'Balonmano', 'host' => 'https://v1.handball.api-sports.io', 'endpoint' => 'games',
            'model' => 'normal', 'sd' => 6, 'tsd' => 7, 'alt' => 3, 'talt' => 5, 'home' => 1.5,
            'leagues' => [
                ['id' => 0, 'season' => 2026, 'name' => 'Liga de balonmano', 'on' => false],
            ],
        ],
        'rugby' => [
            'enabled' => !$fdKey || $apiSportsPaid,
            'name' => 'Rugby', 'host' => 'https://v1.rugby.api-sports.io', 'endpoint' => 'games',
            'model' => 'normal', 'sd' => 14, 'tsd' => 12, 'alt' => 8, 'talt' => 10, 'home' => 3,
            'leagues' => [
                ['id' => 0, 'season' => 2026, 'name' => 'Liga de rugby', 'on' => false],
            ],
        ],
    ],
];
