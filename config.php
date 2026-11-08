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
                ['code' => 'ELC', 'name' => 'Championship',     'on' => true],
                // Desde ESPN (gratis, sin clave). Para quitar una liga pon 'on' => false
                ['code' => 'PER', 'slug' => 'per.1', 'src' => 'espn', 'name' => 'Liga 1 Perú',       'on' => true],
                ['code' => 'ARG', 'slug' => 'arg.1', 'src' => 'espn', 'name' => 'Liga Argentina',    'on' => true],
                ['code' => 'BRA', 'slug' => 'bra.1', 'src' => 'espn', 'name' => 'Brasileirão',       'on' => true],
                ['code' => 'MEX', 'slug' => 'mex.1', 'src' => 'espn', 'name' => 'Liga MX',           'on' => true],
                ['code' => 'COL', 'slug' => 'col.1', 'src' => 'espn', 'name' => 'Liga Colombia',     'on' => true],
                ['code' => 'CHI', 'slug' => 'chi.1', 'src' => 'espn', 'name' => 'Liga Chile',        'on' => true],
                ['code' => 'LIB', 'slug' => 'conmebol.libertadores', 'src' => 'espn', 'name' => 'Copa Libertadores', 'on' => true],
                ['code' => 'SUD', 'slug' => 'conmebol.sudamericana', 'src' => 'espn', 'name' => 'Copa Sudamericana', 'on' => true],
                ['code' => 'MLS', 'slug' => 'usa.1', 'src' => 'espn', 'name' => 'MLS',               'on' => true],
                ['code' => 'UEL', 'slug' => 'uefa.europa', 'src' => 'espn', 'name' => 'Europa League', 'on' => true],
                ['code' => 'UECL', 'slug' => 'uefa.europa.conf', 'src' => 'espn', 'name' => 'Conference League', 'on' => true],
                ['code' => 'TUR', 'slug' => 'tur.1', 'src' => 'espn', 'name' => 'Superliga Turquía', 'on' => true],
                ['code' => 'SCO', 'slug' => 'sco.1', 'src' => 'espn', 'name' => 'Premiership Escocia', 'on' => true],
                ['code' => 'BEL', 'slug' => 'bel.1', 'src' => 'espn', 'name' => 'Liga Bélgica',      'on' => true],
                ['code' => 'KSA', 'slug' => 'ksa.1', 'src' => 'espn', 'name' => 'Liga Saudí',        'on' => true],
                ['code' => 'ECU', 'slug' => 'ecu.1', 'src' => 'espn', 'name' => 'Liga Pro Ecuador', 'on' => true],
                ['code' => 'URU', 'slug' => 'uru.1', 'src' => 'espn', 'name' => 'Liga Uruguay', 'on' => true],
                ['code' => 'PAR', 'slug' => 'par.1', 'src' => 'espn', 'name' => 'Liga Paraguay', 'on' => true],
                ['code' => 'BOL', 'slug' => 'bol.1', 'src' => 'espn', 'name' => 'Liga Bolivia', 'on' => true],
                ['code' => 'VEN', 'slug' => 'ven.1', 'src' => 'espn', 'name' => 'Liga Venezuela', 'on' => true],
                ['code' => 'CRC', 'slug' => 'crc.1', 'src' => 'espn', 'name' => 'Liga Costa Rica', 'on' => true],
                ['code' => 'GUA', 'slug' => 'gua.1', 'src' => 'espn', 'name' => 'Liga Guatemala', 'on' => true],
                ['code' => 'HON', 'slug' => 'hon.1', 'src' => 'espn', 'name' => 'Liga Honduras', 'on' => true],
                ['code' => 'SLV', 'slug' => 'slv.1', 'src' => 'espn', 'name' => 'Liga El Salvador', 'on' => true],
                ['code' => 'BRA2', 'slug' => 'bra.2', 'src' => 'espn', 'name' => 'Brasileirão Serie B', 'on' => true],
                ['code' => 'ARG2', 'slug' => 'arg.2', 'src' => 'espn', 'name' => 'Primera Nacional Argentina', 'on' => true],
                ['code' => 'MEX2', 'slug' => 'mex.2', 'src' => 'espn', 'name' => 'Liga de Expansión MX', 'on' => true],
                ['code' => 'ESP2', 'slug' => 'esp.2', 'src' => 'espn', 'name' => 'LaLiga 2', 'on' => true],
                ['code' => 'ITA2', 'slug' => 'ita.2', 'src' => 'espn', 'name' => 'Serie B Italia', 'on' => true],
                ['code' => 'GER2', 'slug' => 'ger.2', 'src' => 'espn', 'name' => '2. Bundesliga', 'on' => true],
                ['code' => 'FRA2', 'slug' => 'fra.2', 'src' => 'espn', 'name' => 'Ligue 2', 'on' => true],
                ['code' => 'ENG3', 'slug' => 'eng.3', 'src' => 'espn', 'name' => 'League One', 'on' => true],
                ['code' => 'GRE', 'slug' => 'gre.1', 'src' => 'espn', 'name' => 'Superliga Grecia', 'on' => true],
                ['code' => 'AUT', 'slug' => 'aut.1', 'src' => 'espn', 'name' => 'Bundesliga Austria', 'on' => true],
                ['code' => 'SUI', 'slug' => 'sui.1', 'src' => 'espn', 'name' => 'Super League Suiza', 'on' => true],
                ['code' => 'DEN', 'slug' => 'den.1', 'src' => 'espn', 'name' => 'Superliga Dinamarca', 'on' => true],
                ['code' => 'SWE', 'slug' => 'swe.1', 'src' => 'espn', 'name' => 'Allsvenskan', 'on' => true],
                ['code' => 'NOR', 'slug' => 'nor.1', 'src' => 'espn', 'name' => 'Eliteserien', 'on' => true],
                ['code' => 'JPN', 'slug' => 'jpn.1', 'src' => 'espn', 'name' => 'J1 League', 'on' => true],
                ['code' => 'KOR', 'slug' => 'kor.1', 'src' => 'espn', 'name' => 'K League 1', 'on' => false],
                ['code' => 'AUS', 'slug' => 'aus.1', 'src' => 'espn', 'name' => 'A-League', 'on' => true],
                ['code' => 'CDR', 'slug' => 'esp.copa_del_rey', 'src' => 'espn', 'name' => 'Copa del Rey', 'on' => true],
                ['code' => 'FAC', 'slug' => 'eng.fa', 'src' => 'espn', 'name' => 'FA Cup', 'on' => true],
                ['code' => 'ELC2', 'slug' => 'eng.league_cup', 'src' => 'espn', 'name' => 'Carabao Cup', 'on' => true],
                ['code' => 'CIT', 'slug' => 'ita.coppa_italia', 'src' => 'espn', 'name' => 'Coppa Italia', 'on' => true],
                ['code' => 'DFB', 'slug' => 'ger.dfb_pokal', 'src' => 'espn', 'name' => 'DFB Pokal', 'on' => true],
                ['code' => 'CDF', 'slug' => 'fra.coupe_de_france', 'src' => 'espn', 'name' => 'Copa de Francia', 'on' => true],
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
            'model' => 'normal', 'sd' => 12.5, 'tsd' => 17, 'alt' => 7, 'talt' => 13, 'home' => 2.5, 'periods' => true,
            'leagues' => ($apiSportsPaid || !($envKey || $fdKey || $bdlKey)) ? [
                ['id' => 12, 'season' => '2026-2027', 'name' => 'NBA', 'on' => true],
            ] : [
                // 'season' => 'auto' = temporada en curso (la que empieza en octubre)
                ['code' => 'NBA', 'season' => 'auto', 'name' => 'NBA', 'on' => true],
            ],
        ],
        'tenis' => [
            // ATP y WTA desde ESPN (gratis, sin clave). Solo con datos reales.
            'enabled' => (bool)($envKey || $fdKey || $bdlKey),
            'name' => 'Tenis', 'source' => 'espn_tenis', 'model' => 'tenis',
            'leagues' => [
                ['code' => 'atp', 'name' => 'ATP', 'on' => true],
                ['code' => 'wta', 'name' => 'WTA', 'on' => true],
            ],
        ],
        // Esports desde PandaScore (plan gratis). Se activan solos cuando existe el secreto PANDASCORE_KEY.
        'cs2' => [
            'enabled' => (bool)getenv('PANDASCORE_KEY'),
            'name' => 'Counter-Strike 2', 'source' => 'pandascore', 'game' => 'csgo', 'unit' => 'mapas',
            'leagues' => [],
        ],
        'dota2' => [
            'enabled' => (bool)getenv('PANDASCORE_KEY'),
            'name' => 'Dota 2', 'source' => 'pandascore', 'game' => 'dota2', 'unit' => 'mapas',
            'leagues' => [],
        ],
        'lol' => [
            'enabled' => (bool)getenv('PANDASCORE_KEY'),
            'name' => 'League of Legends', 'source' => 'pandascore', 'game' => 'lol', 'unit' => 'mapas',
            'leagues' => [],
        ],
        'valorant' => [
            'enabled' => (bool)getenv('PANDASCORE_KEY'),
            'name' => 'Valorant', 'source' => 'pandascore', 'game' => 'valorant', 'unit' => 'mapas',
            'leagues' => [],
        ],
        'r6' => [
            'enabled' => (bool)getenv('PANDASCORE_KEY'),
            'name' => 'Rainbow Six Siege', 'source' => 'pandascore', 'game' => 'r6siege', 'unit' => 'mapas',
            'leagues' => [],
        ],
        'ow' => [
            'enabled' => (bool)getenv('PANDASCORE_KEY'),
            'name' => 'Overwatch 2', 'source' => 'pandascore', 'game' => 'ow', 'unit' => 'mapas',
            'leagues' => [],
        ],
        'mlbb' => [
            'enabled' => (bool)getenv('PANDASCORE_KEY'),
            'name' => 'Mobile Legends', 'source' => 'pandascore', 'game' => 'mlbb', 'unit' => 'mapas',
            'leagues' => [],
        ],
        'sc2' => [
            'enabled' => (bool)getenv('PANDASCORE_KEY'),
            'name' => 'StarCraft II', 'source' => 'pandascore', 'game' => 'starcraft-2', 'unit' => 'mapas',
            'leagues' => [],
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
