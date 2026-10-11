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
function current_quarter(): array { $year = (int)gmdate('Y'); $month = (int)gmdate('n'); $quarter = intdiv($month - 1, 3); return ['start' => gmmktime(0, 0, 0, $quarter * 3 + 1, 1, $year), 'end' => gmmktime(0, 0, 0, $quarter * 3 + 4, 1, $year), 'number' => $quarter + 1, 'year' => $year]; }
function ca_bundle(): string { $configured = env_value('VATSIM_CA_BUNDLE'); $iniDir = defined('PHP_INI_DIR') ? PHP_INI_DIR : ''; $candidates = array_filter([$configured, __DIR__ . DIRECTORY_SEPARATOR . 'cacert.pem', $iniDir . DIRECTORY_SEPARATOR . 'extras' . DIRECTORY_SEPARATOR . 'ssl' . DIRECTORY_SEPARATOR . 'cacert.pem', $iniDir . DIRECTORY_SEPARATOR . 'extras' . DIRECTORY_SEPARATOR . 'ssl' . DIRECTORY_SEPARATOR . 'cert.pem']); foreach ($candidates as $candidate) if (is_file($candidate)) return $candidate; return ''; }
function http_json(string $url, ?array $post = null, array $headers = []): array { $ch = curl_init($url); $options = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_HTTPHEADER => $headers, CURLOPT_USERAGENT => 'VATSIM-Quarterly-ATC-Activity/1.0', CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2]; $ca = ca_bundle(); if ($ca !== '') $options[CURLOPT_CAINFO] = $ca; curl_setopt_array($ch, $options); if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); } $body = curl_exec($ch); $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $error = curl_error($ch); curl_close($ch); if ($body === false || $error) throw new RuntimeException(($error ?: 'Upstream request failed.') . ($ca === '' ? ' Configure VATSIM_CA_BUNDLE or place cacert.pem beside lib.php.' : '')); $data = json_decode($body, true); if (!is_array($data)) throw new RuntimeException('Invalid upstream response.'); return [$status, $data]; }
function vatsim_history(string $cid, int $quarterStart): array { $records = []; $offset = 0; $limit = 500; do { [$status, $payload] = http_json("https://api.vatsim.net/v2/members/" . rawurlencode($cid) . "/atc?limit=$limit&offset=$offset", null, ['Accept: application/json']); if ($status !== 200) throw new RuntimeException($payload['detail'] ?? "VATSIM API returned $status."); $items = $payload['items'] ?? []; $records = array_merge($records, $items); $offset += count($items); $oldest = PHP_INT_MAX; foreach ($items as $item) $oldest = min($oldest, strtotime($item['connection_id']['start'] ?? 'now')); } while (count($items) === $limit && $offset < ($payload['count'] ?? 0) && $oldest >= $quarterStart); return $records; }
function session_item(array $record): array { return $record['connection_id'] ?? $record; }
function session_callsign(array $record): string { return strtoupper((string)(session_item($record)['callsign'] ?? 'UNKNOWN')); }
function session_minutes(array $record, array $quarter): float { $item = session_item($record); $start = strtotime($item['start'] ?? ''); $end = strtotime($item['end'] ?? ''); if (!$start || !$end) return 0; return max(0, (min($end, $quarter['end'], time()) - max($start, $quarter['start'])) / 60); }
function classify_session(array $record): array {
    $call = session_callsign($record);
    $prefix = explode('_', $call)[0];
    $airport = airport_lookup($prefix);
    if ($airport !== null) {
        $division = $airport['division'] ?: 'Other divisions';
        $artcc = $airport['artcc'] ?: '';
        if ($division === 'VATUSA' && $artcc !== '') return ['VATUSA', 'ARTCC · ' . artcc_name($artcc)];
        if ($division !== 'Other divisions') return [$division, $division];
    }
    $artccs = ['ZAB'=>'Albuquerque','ZAK'=>'Oakland Oceanic','ZAN'=>'Anchorage','ZAU'=>'Chicago','ZBW'=>'Boston','ZDC'=>'Washington','ZDV'=>'Denver','ZFW'=>'Fort Worth','ZHU'=>'Houston','ZID'=>'Indianapolis','ZJX'=>'Jacksonville','ZKC'=>'Kansas City','ZLA'=>'Los Angeles','ZLC'=>'Salt Lake','ZMA'=>'Miami','ZME'=>'Memphis','ZMP'=>'Minneapolis','ZNY'=>'New York','ZOA'=>'Oakland','ZOB'=>'Cleveland','ZSE'=>'Seattle','ZTL'=>'Atlanta'];
    $facilities = ['MEM'=>'ZME','LAX'=>'ZLA','SFO'=>'ZOA','ATL'=>'ZTL','BOS'=>'ZBW','DFW'=>'ZFW','HOU'=>'ZHU','MIA'=>'ZMA','NYC'=>'ZNY','N90'=>'ZNY','PCT'=>'ZDC','PHX'=>'ZAB','SEA'=>'ZSE','SLC'=>'ZLC','STL'=>'ZKC','TPA'=>'ZMA','RDU'=>'ZDC','CHI'=>'ZAU','CLE'=>'ZOB','DEN'=>'ZDV','DTW'=>'ZOB','IND'=>'ZID','JAX'=>'ZJX','PHL'=>'ZNY','PIT'=>'ZOB'];
    if (isset($artccs[$prefix]) || isset($facilities[$prefix])) { $artcc = isset($artccs[$prefix]) ? $prefix : $facilities[$prefix]; return ['VATUSA', 'ARTCC · ' . (isset($artccs[$artcc]) ? $artccs[$artcc] : $artcc)]; }

    // Three-letter US airport identifiers are commonly used for local positions:
    // FYV_GND = KFYV / Drake Field, which belongs to the Memphis ARTCC area.
    $airportArtcc = [
        'FYV' => 'ZME', 'MEM' => 'ZME', 'TUP' => 'ZME', 'MKL' => 'ZME', 'HSV' => 'ZME', 'MGM' => 'ZME',
        'LAX' => 'ZLA', 'BUR' => 'ZLA', 'SAN' => 'ZLA', 'SNA' => 'ZLA', 'ONT' => 'ZLA',
        'SFO' => 'ZOA', 'OAK' => 'ZOA', 'SJC' => 'ZOA', 'SMF' => 'ZOA',
        'ATL' => 'ZTL', 'BOS' => 'ZBW', 'DFW' => 'ZFW', 'HOU' => 'ZHU', 'IAH' => 'ZHU',
        'MIA' => 'ZMA', 'FLL' => 'ZMA', 'JAX' => 'ZJX', 'DEN' => 'ZDV', 'PHX' => 'ZAB',
        'SEA' => 'ZSE', 'PDX' => 'ZSE', 'ORD' => 'ZAU', 'MDW' => 'ZAU', 'DTW' => 'ZOB',
        'CLE' => 'ZOB', 'PIT' => 'ZOB', 'PHL' => 'ZNY', 'EWR' => 'ZNY', 'JFK' => 'ZNY',
        'LGA' => 'ZNY', 'DCA' => 'ZDC', 'IAD' => 'ZDC', 'BWI' => 'ZDC', 'RDU' => 'ZDC',
        'SLC' => 'ZLC', 'MSP' => 'ZMP', 'STL' => 'ZKC', 'MCI' => 'ZKC', 'TPA' => 'ZMA'
    ];
    if (isset($airportArtcc[$prefix])) { $artcc = $airportArtcc[$prefix]; return ['VATUSA', 'ARTCC · ' . (isset($artccs[$artcc]) ? $artccs[$artcc] : $artcc)]; }

    // Facility prefixes are checked before broad regional prefixes. This covers callsigns such as ZSSS_CTR, ZBAA_APP, and ZLLL_APP.
    $facilityDivisions = [
        'ZBAA'=>'VATPRC','ZBBB'=>'VATPRC','ZBHH'=>'VATPRC','ZBTJ'=>'VATPRC','ZBYN'=>'VATPRC','ZGGG'=>'VATPRC','ZGHA'=>'VATPRC','ZGNN'=>'VATPRC','ZGOW'=>'VATPRC','ZGSZ'=>'VATPRC','ZHHH'=>'VATPRC','ZHCC'=>'VATPRC','ZJHK'=>'VATPRC','ZJSY'=>'VATPRC','ZLXY'=>'VATPRC','ZLLL'=>'VATPRC','ZLXN'=>'VATPRC','ZPPP'=>'VATPRC','ZSAM'=>'VATPRC','ZSHC'=>'VATPRC','ZSNJ'=>'VATPRC','ZSQD'=>'VATPRC','ZSSS'=>'VATPRC','ZSWX'=>'VATPRC','ZUCK'=>'VATPRC','ZUGY'=>'VATPRC','ZUUU'=>'VATPRC','ZWWW'=>'VATPRC','ZWCL'=>'VATPRC','ZYCC'=>'VATPRC','ZYHB'=>'VATPRC','ZYTL'=>'VATPRC','ZYTX'=>'VATPRC',
        'CYY'=>'VATCAN','CYZ'=>'VATCAN','CZ'=>'VATCAN','CY'=>'VATCAN','EG'=>'VATSIM UK','ED'=>'VATSIM Germany','ET'=>'VATSIM Germany','LF'=>'VATSIM France','LI'=>'VATSIM Italy','LS'=>'VATSIM Switzerland','LO'=>'VATSIM Austria','EB'=>'VATSIM Belgium','EH'=>'VATSIM Netherlands','LE'=>'VATSIM Spain','LP'=>'VATSIM Portugal','EI'=>'VATSIM Ireland','ES'=>'VATSIM Sweden','EN'=>'VATSIM Norway','EK'=>'VATSIM Denmark','EF'=>'VATSIM Finland','EP'=>'VATSIM Poland','LK'=>'VATSIM Czech Republic','LH'=>'VATSIM Hungary','LR'=>'VATSIM Romania','LT'=>'VATSIM Türkiye','LG'=>'VATSIM Greece','LL'=>'VATSIM Israel','RJ'=>'VATJPN','RO'=>'VATJPN','RK'=>'VATKOR','VH'=>'VATPAC','Y'=>'VATPAC','NZ'=>'VATNZ','FA'=>'VATSIM South Africa','SB'=>'VATSIM Brazil','SA'=>'VATSIM Argentina','SC'=>'VATSIM Chile','MM'=>'VATSIM Mexico','SK'=>'VATSIM Colombia','SP'=>'VATSIM Peru','WS'=>'VATSIM Singapore','WM'=>'VATSIM Malaysia','WI'=>'VATSIM Indonesia','VT'=>'VATSIM Thailand','RP'=>'VATSIM Philippines','RC'=>'VATSIM Taiwan'
    ];
    uksort($facilityDivisions, fn($a, $b) => strlen($b) <=> strlen($a));
    foreach ($facilityDivisions as $facility => $division) if (substr($prefix, 0, strlen($facility)) === $facility) return [$division, $division];

    $divisions = ['PRC'=>'VATPRC','AUS'=>'VATPAC','NZ'=>'VATNZ','JPN'=>'VATJPN','KOR'=>'VATKOR','UK'=>'VATSIM UK','DE'=>'VATSIM Germany','FRA'=>'VATSIM France','ITA'=>'VATSIM Italy','CHE'=>'VATSIM Switzerland','AUT'=>'VATSIM Austria','CAN'=>'VATCAN'];
    foreach ($divisions as $key => $name) if (substr($prefix, 0, strlen($key)) === $key || substr($prefix, 0, strlen('VAT' . $key)) === 'VAT' . $key) return [$name, $name];
    return ['Other divisions', 'Other divisions'];
}
function airport_lookup(string $code): ?array {
    static $db = null;
    static $failed = false;
    if ($failed) return null;
    if ($db === null) {
        if (!extension_loaded('pdo_sqlite') || !is_file(__DIR__ . DIRECTORY_SEPARATOR . 'vatsim.sqlite')) { $failed = true; return null; }
        $db = new PDO('sqlite:' . __DIR__ . DIRECTORY_SEPARATOR . 'vatsim.sqlite');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }
    $statement = $db->prepare('SELECT icao, name, fir, division, artcc FROM airports WHERE icao = :code LIMIT 1');
    $statement->execute([':code' => $code]);
    $airport = $statement->fetch(PDO::FETCH_ASSOC);
    if ($airport !== false) return $airport;
    $statement = $db->prepare('SELECT icao, name, fir, division, artcc FROM airports WHERE iata = :code LIMIT 1');
    $statement->execute([':code' => $code]);
    $airport = $statement->fetch(PDO::FETCH_ASSOC);
    return $airport === false ? null : $airport;
}
function artcc_name(string $code): string {
    $names = ['ZAB'=>'Albuquerque','ZAK'=>'Oakland Oceanic','ZAN'=>'Anchorage','ZAU'=>'Chicago','ZBW'=>'Boston','ZDC'=>'Washington','ZDV'=>'Denver','ZFW'=>'Fort Worth','ZHU'=>'Houston','ZID'=>'Indianapolis','ZJX'=>'Jacksonville','ZKC'=>'Kansas City','ZLA'=>'Los Angeles','ZLC'=>'Salt Lake','ZMA'=>'Miami','ZME'=>'Memphis','ZMP'=>'Minneapolis','ZNY'=>'New York','ZOA'=>'Oakland','ZOB'=>'Cleveland','ZSE'=>'Seattle','ZTL'=>'Atlanta'];
    return $names[$code] ?? $code;
}
function oauth_configured(): bool { return env_value('VATSIM_CLIENT_ID') !== '' && env_value('VATSIM_CLIENT_SECRET') !== ''; }
function oauth_token(string $code): array { [$status, $data] = http_json('https://auth-dev.vatsim.net/oauth/token', ['grant_type'=>'authorization_code','client_id'=>env_value('VATSIM_CLIENT_ID'),'client_secret'=>env_value('VATSIM_CLIENT_SECRET'),'redirect_uri'=>env_value('VATSIM_REDIRECT_URI','https://vqaa.jurina.org/auth/callback'),'code'=>$code], ['Content-Type: application/x-www-form-urlencoded']); if ($status !== 200) throw new RuntimeException('VATSIM token exchange failed.'); return $data; }
function vatsim_user(string $token): array { [$status, $data] = http_json('https://auth-dev.vatsim.net/api/user', null, ['Accept: application/json', 'Authorization: Bearer ' . $token]); if ($status !== 200) throw new RuntimeException('VATSIM user request failed.'); return $data; }
