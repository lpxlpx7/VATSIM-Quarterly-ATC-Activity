<?php
declare(strict_types=1);

function env_value(string $key, string $default = ''): string {
    static $values = null;
    if ($values === null) {
        $values = [];
        $file = __DIR__ . DIRECTORY_SEPARATOR . '.env';
        if (is_file($file)) foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (preg_match('/^\s*([A-Z_][A-Z0-9_]*)\s*=\s*(.*?)\s*$/i', $line, $m)) $values[$m[1]] = trim($m[2], " \t\"'");
        }
    }
    return $_ENV[$key] ?? $_SERVER[$key] ?? $values[$key] ?? $default;
}
function json_response(array $data, int $status = 200): void { header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store'); http_response_code($status); echo json_encode($data, JSON_UNESCAPED_UNICODE); exit;
}
function current_quarter(int $offset = 0): array { $year = (int)gmdate('Y'); $month = (int)gmdate('n'); $quarter = intdiv($month - 1, 3) + $offset; $year += (int)floor($quarter / 4); $quarter = (($quarter % 4) + 4) % 4; return ['start' => gmmktime(0, 0, 0, $quarter * 3 + 1, 1, $year), 'end' => gmmktime(0, 0, 0, $quarter * 3 + 4, 1, $year), 'number' => $quarter + 1, 'year' => $year]; }
function requested_quarter(): array { $value = strtoupper(trim((string)($_GET['quarter'] ?? ''))); if ($value === '') return current_quarter(); if (!preg_match('/^(\d{4})-Q([1-4])$/', $value, $match)) throw new InvalidArgumentException('Invalid quarter.'); $year = (int)$match[1]; $number = (int)$match[2]; if ($year < 2000 || $year > (int)gmdate('Y') + 1) throw new InvalidArgumentException('Invalid quarter.'); $start = gmmktime(0, 0, 0, ($number - 1) * 3 + 1, 1, $year); return ['start' => $start, 'end' => gmmktime(0, 0, 0, $number * 3 + 1, 1, $year), 'number' => $number, 'year' => $year]; }
function ca_bundle(): string { $configured = env_value('VATSIM_CA_BUNDLE'); $iniDir = defined('PHP_INI_DIR') ? PHP_INI_DIR : ''; $candidates = array_filter([$configured, __DIR__ . DIRECTORY_SEPARATOR . 'cacert.pem', $iniDir . DIRECTORY_SEPARATOR . 'extras' . DIRECTORY_SEPARATOR . 'ssl' . DIRECTORY_SEPARATOR . 'cacert.pem', $iniDir . DIRECTORY_SEPARATOR . 'extras' . DIRECTORY_SEPARATOR . 'ssl' . DIRECTORY_SEPARATOR . 'cert.pem']); foreach ($candidates as $candidate) if (is_file($candidate)) return $candidate; return ''; }
function http_json(string $url, ?array $post = null, array $headers = []): array { $ch = curl_init($url); $options = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_HTTPHEADER => $headers, CURLOPT_USERAGENT => 'VATSIM-Quarterly-ATC-Activity/1.0', CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2]; $ca = ca_bundle(); if ($ca !== '') $options[CURLOPT_CAINFO] = $ca; curl_setopt_array($ch, $options); if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); } $body = curl_exec($ch); $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $error = curl_error($ch); curl_close($ch); if ($body === false || $error) throw new RuntimeException(($error ?: 'Upstream request failed.') . ($ca === '' ? ' Configure VATSIM_CA_BUNDLE or place cacert.pem beside lib.php.' : '')); $data = json_decode($body, true); if (!is_array($data)) throw new RuntimeException('Invalid upstream response.'); return [$status, $data]; }
function vatsim_history(string $cid, int $quarterStart): array { $records = []; $offset = 0; $limit = 500; do { [$status, $payload] = http_json("https://api.vatsim.net/v2/members/" . rawurlencode($cid) . "/atc?limit=$limit&offset=$offset", null, ['Accept: application/json']); if ($status !== 200) throw new RuntimeException($payload['detail'] ?? "VATSIM API returned $status."); $items = $payload['items'] ?? []; $records = array_merge($records, $items); $offset += count($items); $oldest = PHP_INT_MAX; foreach ($items as $item) $oldest = min($oldest, strtotime($item['connection_id']['start'] ?? 'now')); } while (count($items) === $limit && $offset < ($payload['count'] ?? 0) && $oldest >= $quarterStart); return $records; }
function session_item(array $record): array { return $record['connection_id'] ?? $record; }
function session_callsign(array $record): string { return strtoupper((string)(session_item($record)['callsign'] ?? 'UNKNOWN')); }
function session_minutes(array $record, array $quarter): float { $item = session_item($record); $start = strtotime($item['start'] ?? ''); $end = strtotime($item['end'] ?? ''); if (!$start || !$end) return 0; return max(0, (min($end, $quarter['end'], time()) - max($start, $quarter['start'])) / 60); }
// ---------------------------------------------------------------------------
// Callsign classification
//
// Every ATC callsign is resolved to [VATSIM division, area]. Resolution order:
//   1. Special callsigns (NAT, ZAK, Australian/UK TMA names that are not ICAO).
//   2. VATUSA vNAS facilities/positions (MEM, BNA, HSV, SCT, N90, LAS, HNL, GUM ...).
//   3. Airport identifier: exact ICAO (ZSPD, KLAS, RJTT), FAA LID via K + code
//      (FYV -> KFYV), then IATA (HNL -> PHNL, YYZ -> CYYZ).
//   4. VATSpy FIR / sector callsign prefix (EDGG, ZSHA, LON_S, TOR, BN-ARA, MNL_CE).
//      CTR/FSS callsigns try this before step 3, local positions try it after.
//   5. ICAO nationality letters (any unlisted airfield such as ZSXX or LEXX).
// Only airspace without a VATSIM division (NAT shared oceanic, Mongolia,
// North Korea, Antarctica) or a callsign that matches nothing ends up in
// "Other divisions".
// ---------------------------------------------------------------------------

const OTHER_DIVISIONS = 'Other divisions';

function callsign_data(): array {
    static $data = null;
    if ($data === null) {
        $file = __DIR__ . DIRECTORY_SEPARATOR . 'callsign-data.php';
        $data = is_file($file) ? require $file : [];
        $data += ['airports' => [], 'iata' => [], 'firNames' => [], 'firPrefixes' => [], 'us' => []];
    }
    return $data;
}

function division_names(): array {
    return [
        'USA' => 'VATUSA', 'CAN' => 'VATCAN', 'CAR' => 'VATCAR', 'CA' => 'VATCA', 'MEX' => 'VATMEX',
        'BRZ' => 'VATBRZ', 'SUR' => 'VATSUR', 'EUD' => 'VATEUD', 'UK' => 'VATUK', 'IL' => 'VATIL',
        'MENA' => 'VATMENA', 'RUS' => 'VATRUS', 'SSA' => 'VATSSA', 'WA' => 'VATWA', 'SEA' => 'VATSEA',
        'PRC' => 'VATPRC', 'ROC' => 'VATROC', 'JPN' => 'VATJPN', 'KOR' => 'VATKOR', 'NZ' => 'VATNZ',
        'PAC' => 'VATPAC', 'OTHER' => OTHER_DIVISIONS,
    ];
}

function artcc_names(): array {
    return ['ZAB'=>'Albuquerque','ZAN'=>'Anchorage','ZAU'=>'Chicago','ZBW'=>'Boston','ZDC'=>'Washington','ZDV'=>'Denver','ZFW'=>'Fort Worth','ZHN'=>'Honolulu','ZHU'=>'Houston','ZID'=>'Indianapolis','ZJX'=>'Jacksonville','ZKC'=>'Kansas City','ZLA'=>'Los Angeles','ZLC'=>'Salt Lake','ZMA'=>'Miami','ZME'=>'Memphis','ZMP'=>'Minneapolis','ZNY'=>'New York','ZOA'=>'Oakland','ZOB'=>'Cleveland','ZSE'=>'Seattle','ZTL'=>'Atlanta','ZUA'=>'Guam'];
}
function artcc_name(string $code): string { return artcc_names()[$code] ?? $code; }

// ICAO nationality letters -> [division code, area]. Two-letter keys win over one-letter keys.
function icao_country_table(): array {
    static $table = null;
    if ($table !== null) return $table;
    $e = fn(string $vacc) => ['EUD', 'vACC · ' . $vacc];
    $table = [
        // Americas
        'K' => ['USA', ''], 'C' => ['CAN', 'Canada'], 'MM' => ['MEX', 'Mexico'],
        'MG' => ['CA', 'Guatemala'], 'MH' => ['CA', 'Honduras'], 'MN' => ['CA', 'Nicaragua'], 'MP' => ['CA', 'Panama'],
        'MR' => ['CA', 'Costa Rica'], 'MS' => ['CA', 'El Salvador'], 'MZ' => ['CA', 'Belize'],
        'MB' => ['CAR', 'Turks and Caicos'], 'MD' => ['CAR', 'Santo Domingo'], 'MK' => ['CAR', 'Kingston'], 'MT' => ['CAR', 'Port-au-Prince'],
        'MU' => ['CAR', 'Havana'], 'MW' => ['CAR', 'Cayman Islands'], 'MY' => ['CAR', 'Nassau'],
        'TA' => ['CAR', 'Piarco'], 'TB' => ['CAR', 'Piarco'], 'TD' => ['CAR', 'Piarco'], 'TF' => ['CAR', 'Piarco'], 'TG' => ['CAR', 'Piarco'],
        'TK' => ['CAR', 'Piarco'], 'TL' => ['CAR', 'Piarco'], 'TQ' => ['CAR', 'San Juan'], 'TR' => ['CAR', 'Piarco'], 'TT' => ['CAR', 'Piarco'],
        'TV' => ['CAR', 'Piarco'], 'TU' => ['CAR', 'San Juan'], 'TI' => ['CAR', 'San Juan'], 'TJ' => ['CAR', 'San Juan'], 'TN' => ['CAR', 'Curaçao'],
        'TX' => ['USA', 'ZNY'],
        'SB' => ['BRZ', 'Brazil'], 'SD' => ['BRZ', 'Brazil'], 'SI' => ['BRZ', 'Brazil'], 'SJ' => ['BRZ', 'Brazil'], 'SN' => ['BRZ', 'Brazil'],
        'SS' => ['BRZ', 'Brazil'], 'SW' => ['BRZ', 'Brazil'],
        'SA' => ['SUR', 'Argentina'], 'SC' => ['SUR', 'Chile'], 'SE' => ['SUR', 'Ecuador'], 'SG' => ['SUR', 'Paraguay'], 'SK' => ['SUR', 'Colombia'],
        'SL' => ['SUR', 'Bolivia'], 'SM' => ['SUR', 'Guyanas'], 'SO' => ['SUR', 'Guyanas'], 'SY' => ['SUR', 'Guyanas'], 'SP' => ['SUR', 'Peru'],
        'SU' => ['SUR', 'Uruguay'], 'SV' => ['SUR', 'Venezuela'], 'SF' => ['OTHER', 'Falkland Islands'],
        // Europe
        'EG' => ['UK', 'United Kingdom'], 'ED' => $e('Germany'), 'ET' => $e('Germany'), 'EB' => $e('Belux'), 'EL' => $e('Belux'),
        'EH' => $e('Dutch'), 'EI' => $e('Ireland'), 'EK' => $e('Scandinavia'), 'EN' => $e('Scandinavia'), 'ES' => $e('Scandinavia'),
        'BG' => $e('Scandinavia'), 'EF' => $e('Finland'), 'BI' => $e('Iceland'), 'EE' => $e('Estonia'), 'EV' => $e('Latvia'),
        'EY' => $e('Lithuania'), 'EP' => $e('Poland'), 'EU' => $e('Eurocontrol'),
        'LF' => $e('France'), 'LN' => $e('France'), 'LI' => $e('Italy'), 'LE' => $e('Spain'), 'GC' => $e('Spain'), 'GE' => $e('Spain'),
        'LX' => $e('Spain'), 'LP' => $e('Portugal'), 'LS' => $e('Switzerland'), 'LO' => $e('Austria'), 'LK' => $e('Czech Republic'),
        'LZ' => $e('Slovak Republic'), 'LH' => $e('Hungary'), 'LR' => $e('Romania'), 'LB' => $e('Bulgaria'), 'LG' => $e('Hellenic'),
        'LC' => $e('Cyprus'), 'LM' => $e('Malta'), 'LT' => $e('Turkey'), 'LU' => $e('Moldova'), 'LA' => $e('Albania'),
        'LD' => $e('Adria'), 'LJ' => $e('Adria'), 'LQ' => $e('Adria'), 'LW' => $e('Adria'), 'LY' => $e('Adria'), 'BK' => $e('Adria'),
        'UK' => $e('Ukraine'), 'UG' => $e('Georgia'),
        'LL' => ['IL', 'Israel'], 'LV' => ['IL', 'Israel'],
        // Russia and Central Asia
        'U' => ['RUS', 'Russia'], 'X' => ['RUS', 'Russia'], 'UM' => ['RUS', 'Belarus'], 'UMK' => ['RUS', 'Russia'],
        'UA' => ['RUS', 'Kazakhstan'], 'UB' => ['RUS', 'Azerbaijan'], 'UC' => ['RUS', 'Kyrgyzstan'], 'UD' => ['RUS', 'Armenia'],
        'UT' => ['RUS', 'Central Asia'],
        // Middle East and North Africa
        'DA' => ['MENA', 'Algeria'], 'DT' => ['MENA', 'Tunisia'], 'GM' => ['MENA', 'Morocco'], 'GS' => ['MENA', 'Morocco'],
        'HE' => ['MENA', 'Egypt'], 'HL' => ['MENA', 'Libya'], 'HS' => ['MENA', 'North East Africa'],
        'OB' => ['MENA', 'Khaleej'], 'OE' => ['MENA', 'Saudi Arabia'], 'OI' => ['MENA', 'Iran'], 'OJ' => ['MENA', 'Jordan'],
        'OK' => ['MENA', 'Kuwait'], 'OL' => ['MENA', 'Lebanon'], 'OM' => ['MENA', 'Khaleej'], 'OO' => ['MENA', 'Khaleej'],
        'OR' => ['MENA', 'Iraq'], 'OS' => ['MENA', 'Syria'], 'OT' => ['MENA', 'Khaleej'], 'OY' => ['MENA', 'Yemen'],
        // Sub-Saharan Africa
        'D' => ['SSA', 'Africa'], 'F' => ['SSA', 'Africa'], 'G' => ['SSA', 'Africa'], 'H' => ['SSA', 'Africa'],
        'FA' => ['SSA', 'South Africa'], 'HK' => ['SSA', 'Kenya'], 'DN' => ['SSA', 'Nigeria'], 'DG' => ['SSA', 'Ghana'], 'HA' => ['SSA', 'Ethiopia'],
        // West Asia
        'OA' => ['WA', 'Afghanistan'], 'OP' => ['WA', 'Pakistan'], 'VA' => ['WA', 'India'], 'VE' => ['WA', 'India'], 'VI' => ['WA', 'India'],
        'VO' => ['WA', 'India'], 'VC' => ['WA', 'Sri Lanka'], 'VG' => ['WA', 'Bangladesh'], 'VN' => ['WA', 'Nepal'], 'VQ' => ['WA', 'Bhutan'],
        'VR' => ['WA', 'Maldives'],
        // Southeast Asia
        'VH' => ['SEA', 'Hong Kong'], 'VM' => ['SEA', 'Hong Kong'], 'VD' => ['SEA', 'Vietnam Cambodia Laos'], 'VL' => ['SEA', 'Vietnam Cambodia Laos'],
        'VV' => ['SEA', 'Vietnam Cambodia Laos'], 'VT' => ['SEA', 'Thailand'], 'VY' => ['SEA', 'Myanmar'], 'RP' => ['SEA', 'Philippines'],
        'W' => ['SEA', 'Indonesia'], 'WS' => ['SEA', 'Singapore'], 'WM' => ['SEA', 'Malaysia'], 'WB' => ['SEA', 'Malaysia'], 'WP' => ['SEA', 'Timor-Leste'],
        // East Asia
        'Z' => ['PRC', 'China'], 'ZB' => ['PRC', 'FIR · Beijing'], 'ZS' => ['PRC', 'FIR · Shanghai'], 'ZG' => ['PRC', 'FIR · Guangzhou'],
        'ZH' => ['PRC', 'FIR · Wuhan'], 'ZJ' => ['PRC', 'FIR · Sanya'], 'ZL' => ['PRC', 'FIR · Lanzhou'], 'ZP' => ['PRC', 'FIR · Kunming'],
        'ZU' => ['PRC', 'FIR · Chengdu'], 'ZW' => ['PRC', 'FIR · Urumqi'], 'ZY' => ['PRC', 'FIR · Shenyang'],
        'ZK' => ['OTHER', 'North Korea (no division)'], 'ZM' => ['OTHER', 'Mongolia (no division)'],
        'RC' => ['ROC', 'Taiwan'], 'RJ' => ['JPN', 'Japan'], 'RO' => ['JPN', 'Japan'], 'RK' => ['KOR', 'Korea'],
        // Pacific
        'Y' => ['PAC', 'Australia'], 'A' => ['PAC', 'Pacific'], 'AY' => ['PAC', 'Papua New Guinea'], 'AG' => ['PAC', 'Solomon Islands'],
        'N' => ['PAC', 'Pacific'], 'NF' => ['PAC', 'Fiji'], 'NV' => ['PAC', 'Vanuatu'], 'NW' => ['PAC', 'New Caledonia'], 'NT' => ['PAC', 'French Polynesia'],
        'NG' => ['PAC', 'Kiribati'], 'NL' => ['PAC', 'Wallis and Futuna'], 'PC' => ['PAC', 'Kiribati'], 'PL' => ['PAC', 'Kiribati'],
        'PK' => ['PAC', 'Marshall Islands'], 'PT' => ['PAC', 'Micronesia'],
        'NZ' => ['NZ', 'New Zealand'], 'NC' => ['NZ', 'Cook Islands'], 'NI' => ['NZ', 'Niue'], 'NS' => ['NZ', 'Samoa'], 'NFT' => ['NZ', 'Tonga'], 'LFV' => ['CAN', 'FIR · Moncton/Gander'],
        'P' => ['USA', 'ZOA'], 'PA' => ['USA', 'ZAN'], 'PF' => ['USA', 'ZAN'], 'PO' => ['USA', 'ZAN'], 'PP' => ['USA', 'ZAN'],
        'PH' => ['USA', 'ZHN'], 'PG' => ['USA', 'ZUA'],
        // No VATSIM division
        'AT' => ['OTHER', 'Antarctica'],
    ];
    return $table;
}

function icao_country(string $code): ?array {
    $table = icao_country_table();
    for ($length = min(3, strlen($code)); $length >= 1; $length--) {
        $key = substr($code, 0, $length);
        if (isset($table[$key])) return $table[$key];
    }
    return null;
}

// Turns [division code, area] into the display pair, expanding US ARTCC codes.
function division_result(string $division, string $area, string $fallbackArtcc = ''): array {
    $name = division_names()[$division] ?? OTHER_DIVISIONS;
    if ($division === 'USA') {
        $artcc = isset(artcc_names()[$area]) ? $area : $fallbackArtcc;
        return [$name, $artcc !== '' ? 'ARTCC · ' . artcc_name($artcc) : 'ARTCC · Unknown'];
    }
    return [$name, $area !== '' ? $area : $name];
}

// vNAS facility -> display pair. San Juan CERAP (ZSU) runs on vNAS but belongs to VATCAR.
function vnas_result(string $artcc): array {
    if ($artcc === 'ZSU') return division_result('CAR', 'San Juan');
    return division_result('USA', $artcc);
}

function us_artcc_for_fir(string $fir): string {
    $special = ['KZAK' => 'ZOA', 'KZA1' => 'ZOA', 'PHZH' => 'ZHN', 'PGZU' => 'ZUA'];
    if (isset($special[$fir])) return $special[$fir];
    if (substr($fir, 0, 4) === 'PAZA') return 'ZAN';
    if (substr($fir, 0, 2) === 'KZ') return substr($fir, 1, 3);
    return '';
}

function canada_area_for_fir(string $fir): string {
    $names = ['CZYZ' => 'Toronto', 'CZUL' => 'Montreal', 'CZZV' => 'Montreal', 'CZVR' => 'Vancouver', 'CZEG' => 'Edmonton', 'CZWG' => 'Winnipeg', 'CZQM' => 'Moncton/Gander', 'CZQX' => 'Moncton/Gander', 'CZQO' => 'Gander Oceanic'];
    $fir = substr($fir, 0, 4);
    return isset($names[$fir]) ? 'FIR · ' . $names[$fir] : 'Canada';
}

// Division of an airspace (FIR/sector) code from VATSpy.
function classify_fir(string $fir): ?array {
    $fir = strtoupper($fir);
    $base = preg_split('/[-_]/', $fir)[0];
    $special = [
        'NAT' => ['OTHER', 'North Atlantic (NAT)'], 'ADR' => ['EUD', 'vACC · Adria'], 'UKR' => ['EUD', 'vACC · Ukraine'],
        'BAIRES' => ['SUR', 'Argentina'], 'GYA' => ['SUR', 'Guyanas'], 'TEH' => ['MENA', 'Iran'], 'XVCL' => ['SEA', 'Vietnam Cambodia Laos'],
        'EGYP' => ['OTHER', 'Falkland Islands'], 'SPI1' => ['SUR', 'Peru'], 'SPI2' => ['SUR', 'Peru'], 'SPI3' => ['SUR', 'Peru'],
        'TJZS' => ['CAR', 'San Juan'], 'NZZO' => ['NZ', 'New Zealand'], 'NZCM' => ['OTHER', 'Antarctica'],
    ];
    if (isset($special[$base])) return division_result(...$special[$base]);
    if (substr($fir, 0, 2) === 'EG') return division_result('UK', ['EGPX' => 'FIR · Scottish', 'EGGX' => 'Shanwick Oceanic'][$base] ?? 'FIR · London');
    $artcc = us_artcc_for_fir($fir);
    if ($artcc !== '') return division_result('USA', $artcc);
    if ($fir[0] === 'C') return division_result('CAN', canada_area_for_fir($fir));
    $country = icao_country($base);
    return $country === null ? null : division_result($country[0], $country[1]);
}

// Division of a 4-letter ICAO airport (known to VATSpy or not).
function classify_airport(string $icao): ?array {
    $data = callsign_data();
    $fir = $data['airports'][$icao] ?? '';
    $us = $data['us'];
    if ($icao === 'PKWA' || $icao === 'TXKF') return classify_fir($fir); // VATUSA exceptions (Kwajalein, Bermuda)
    // Non-ICAO identifiers (Russian internal X***/digit codes, GUAM ...) and Antarctic
    // stations are classified by the airspace they sit in.
    if ($fir !== '' && ($fir === 'NZCM' || $icao[0] === 'X' || preg_match('/\d/', $icao) || !icao_country($icao) || $icao === 'GUAM')) return classify_fir($fir);
    if ($icao[0] === 'K' || ($fir !== '' && substr($fir, 0, 4) === 'PAZA')) {
        $artcc = $us[substr($icao, 1)] ?? $us[$icao] ?? us_artcc_for_fir($fir);
        return vnas_result($artcc);
    }
    $country = icao_country($icao);
    if ($country === null) return $fir !== '' ? classify_fir($fir) : null;
    if ($country[0] === 'USA') return vnas_result($us[substr($icao, 1)] ?? $us[$icao] ?? (us_artcc_for_fir($fir) ?: $country[1]));
    if ($country[0] === 'CAN') return division_result('CAN', canada_area_for_fir($fir));
    if ($country[0] === 'UK' && $fir !== '') return classify_fir($fir) ?? division_result(...$country);
    return division_result($country[0], $country[1]);
}

// Airport from the first callsign segment: ICAO, FAA LID (K + code) or IATA.
function airport_for_identifier(string $id): ?string {
    $data = callsign_data();
    if (strlen($id) === 4 && isset($data['airports'][$id])) return $id;
    if (strlen($id) === 3) {
        if (isset($data['airports']['K' . $id])) return 'K' . $id;
        if (isset($data['iata'][$id])) return $data['iata'][$id];
    }
    return null;
}

// Longest VATSpy FIR/sector prefix that matches whole callsign segments.
function fir_for_segments(array $parts): ?string {
    $prefixes = callsign_data()['firPrefixes'];
    for ($n = count($parts); $n >= 1; $n--) {
        $key = implode('_', array_slice($parts, 0, $n));
        if (isset($prefixes[$key])) return $prefixes[$key];
    }
    // Sector variants such as SCO_X_CTR (prefix SCO_E) or BN-XYZ_CTR (prefix BN-ARA).
    static $firstSegment = null;
    if ($firstSegment === null) {
        $firstSegment = [];
        foreach ($prefixes as $prefix => $fir) {
            $segment = explode('_', (string)$prefix)[0];
            $firstSegment[$segment] ??= $fir;
            $base = explode('-', $segment)[0];
            if ($base !== $segment && strlen($base) >= 2) $firstSegment[$base . '-'] ??= $fir;
        }
    }
    if (isset($firstSegment[$parts[0]])) return $firstSegment[$parts[0]];
    $base = explode('-', $parts[0])[0];
    if ($base !== $parts[0] && isset($firstSegment[$base . '-'])) return $firstSegment[$base . '-'];
    return null;
}

function classify_callsign(string $callsign): array {
    $callsign = strtoupper(trim($callsign));
    $parts = array_values(array_filter(explode('_', $callsign), fn($part) => $part !== ''));
    if (!$parts) return [OTHER_DIVISIONS, 'Unrecognized'];
    $first = $parts[0];
    $suffix = count($parts) > 1 ? end($parts) : '';
    $enroute = in_array($suffix, ['CTR', 'FSS'], true);
    $data = callsign_data();

    // 1. Special callsigns that are neither airports nor VATSpy prefixes.
    $special = [
        'NAT' => ['OTHER', 'North Atlantic (NAT)'], 'ZAK' => ['USA', 'ZOA'], 'ZAN' => ['USA', 'ZAN'], 'HCF' => ['USA', 'ZHN'],
        // VATUK TMA / approach callsigns
        'ESSEX' => ['UK', 'FIR · London'], 'THAMES' => ['UK', 'FIR · London'], 'SOLENT' => ['UK', 'FIR · London'],
        // VATPAC TCU callsigns (SY_APP, BN-D_APP ...)
        'SY' => ['PAC', 'Australia'], 'BN' => ['PAC', 'Australia'], 'ML' => ['PAC', 'Australia'], 'AD' => ['PAC', 'Australia'],
        'PH' => ['PAC', 'Australia'], 'CS' => ['PAC', 'Australia'], 'DN' => ['PAC', 'Australia'], 'CB' => ['PAC', 'Australia'],
        'TL' => ['PAC', 'Australia'], 'RK' => ['PAC', 'Australia'], 'HB' => ['PAC', 'Australia'], 'LT' => ['PAC', 'Australia'],
        'OK' => ['PAC', 'Australia'], 'NW' => ['PAC', 'Australia'], 'WLM' => ['PAC', 'Australia'], 'AMB' => ['PAC', 'Australia'],
        'TN' => ['PAC', 'Australia'], 'ES' => ['PAC', 'Australia'],
    ];
    if ($enroute && $first === 'STC') return division_result('UK', 'FIR · Scottish'); // Scottish TC; STC_TWR is St. Cloud (ZMP)
    $firstBase = explode('-', $first)[0];
    if (isset($special[$first])) return division_result(...$special[$first]);
    if ($firstBase !== $first && isset($special[$firstBase]) && strlen($firstBase) <= 3) return division_result(...$special[$firstBase]);

    // 2. VATUSA vNAS facilities and position prefixes. CTR/FSS callsigns check
    // VATSpy first so that, for example, MCN_CTR stays Moncton (VATCAN).
    $usArtcc = $data['us'][$first] ?? null;
    $fir = fir_for_segments($parts);
    if ($enroute) {
        if ($fir !== null) { $result = classify_fir($fir); if ($result !== null) return $result; }
        if ($usArtcc !== null) return vnas_result($usArtcc);
    } elseif ($usArtcc !== null) {
        return vnas_result($usArtcc);
    }

    // 3. Airport identifier.
    $airport = airport_for_identifier($first);
    if ($airport !== null) { $result = classify_airport($airport); if ($result !== null) return $result; }

    // 4. FIR / sector prefix for local positions.
    if ($fir !== null) { $result = classify_fir($fir); if ($result !== null) return $result; }

    // 5. ICAO nationality letters for airfields VATSpy does not list.
    if (preg_match('/^[A-Z][A-Z0-9]{3}$/', $first)) { $result = classify_airport($first); if ($result !== null) return $result; }

    return [OTHER_DIVISIONS, 'Unrecognized'];
}

function classify_session(array $record): array { return classify_callsign(session_callsign($record)); }

function oauth_configured(): bool { return env_value('VATSIM_CLIENT_ID') !== '' && env_value('VATSIM_CLIENT_SECRET') !== ''; }
function oauth_token(string $code): array { [$status, $data] = http_json('https://auth-dev.vatsim.net/oauth/token', ['grant_type'=>'authorization_code','client_id'=>env_value('VATSIM_CLIENT_ID'),'client_secret'=>env_value('VATSIM_CLIENT_SECRET'),'redirect_uri'=>env_value('VATSIM_REDIRECT_URI','https://vqaa.jurina.org/auth/callback'),'code'=>$code], ['Content-Type: application/x-www-form-urlencoded']); if ($status !== 200) throw new RuntimeException('VATSIM token exchange failed.'); return $data; }
function vatsim_user(string $token): array { [$status, $data] = http_json('https://auth-dev.vatsim.net/api/user', null, ['Accept: application/json', 'Authorization: Bearer ' . $token]); if ($status !== 200) throw new RuntimeException('VATSIM user request failed.'); return $data; }
