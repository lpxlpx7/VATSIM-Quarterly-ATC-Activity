<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib.php';
session_start();
$action = $_GET['action'] ?? (($_SERVER['REQUEST_URI'] ?? '') === '/auth/callback' ? 'callback' : 'login');

if ($action === 'status') {
    json_response(['authenticated' => isset($_SESSION['vatsim_user']), 'user' => $_SESSION['vatsim_user'] ?? null]);
}
if ($action === 'logout') {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) setcookie(session_name(), '', time() - 42000, '/');
    session_destroy();
    header('Location: /');
    exit;
}
if ($action === 'callback') {
    $code = $_GET['code'] ?? '';
    $state = $_GET['state'] ?? '';
    if (!$code || !$state || empty($_SESSION['oauth_state']) || !hash_equals($_SESSION['oauth_state'], $state)) { http_response_code(400); exit('Invalid OAuth callback.'); }
    unset($_SESSION['oauth_state']);
    $token = oauth_token($code);
    $user = vatsim_user($token['access_token'] ?? '');
    $_SESSION['vatsim_user'] = ['name' => $user['data']['personal']['name_full'] ?? 'VATSIM member', 'cid' => (string)($user['data']['cid'] ?? '')];
    header('Location: /');
    exit;
}

if (!oauth_configured()) { http_response_code(503); exit('Sandbox OAuth is not configured.'); }
$_SESSION['oauth_state'] = bin2hex(random_bytes(24));
$query = http_build_query(['response_type' => 'code', 'client_id' => env_value('VATSIM_CLIENT_ID'), 'redirect_uri' => env_value('VATSIM_REDIRECT_URI', 'https://vqaa.jurina.org/auth/callback'), 'scope' => 'full_name vatsim_details', 'state' => $_SESSION['oauth_state']]);
header('Location: https://auth-dev.vatsim.net/oauth/authorize?' . $query);
exit;
