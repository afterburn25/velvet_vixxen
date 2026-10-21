<?php
declare(strict_types=1);
require_once ROOT_PATH . '/includes/bootstrap.php';
if (!is_installed()) { http_response_code(503); exit('This website is not available yet.'); }
vvx_access_private();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'POST'], true)) { header('Allow: GET, POST'); http_response_code(405); exit('Method not allowed.'); }
$ageAccess = new App\Core\AgeAccess(app_config()); $accessError = ''; $accessNotice = ''; $accessPage = true;
if ($method === 'POST') {
    try {
        if (!verify_csrf()) throw new RuntimeException('Your form session expired. Reload before continuing.', 419);
        $action = $_POST['action'] ?? null;
        if ($action === 'age_start') { $destination = $ageAccess->begin($_SESSION, $_SERVER); header('Location: ' . $destination, true, 303); exit; }
        if ($action !== 'age_complete') throw new InvalidArgumentException('Choose an age verification action.');
        if ($ageAccess->complete($_SESSION, $_SERVER)) { header('Location: ' . base_url(), true, 303); exit; }
        $accessNotice = 'The check is still processing. Return here after completing it and try again.';
    } catch (Throwable $caught) {
        vvx_response_status(in_array((int) $caught->getCode(), [403, 405, 409, 419, 429, 503], true) ? (int) $caught->getCode() : 422);
        $accessError = $caught instanceof RuntimeException || $caught instanceof InvalidArgumentException ? $caught->getMessage() : 'The age check is unavailable. No access was granted.';
    }
}
$accessDecision = $ageAccess->decision($_SESSION, $_SERVER);
if ($accessError === '' && $accessDecision !== 'age' && $accessDecision !== 'allowed') http_response_code($accessDecision === 'unavailable' ? 503 : 403);
require ROOT_PATH . '/app/Views/public/access.php';
