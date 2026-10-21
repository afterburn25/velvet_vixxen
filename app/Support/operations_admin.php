<?php
declare(strict_types=1);

function vvx_operations_owner(bool $recent = false): array
{
    $identity = app_auth()->user();
    if (!is_array($identity) || ($identity['role'] ?? '') !== 'owner') throw new RuntimeException('Only the active site owner can manage Operations.',403);
    (new App\Core\Store(new App\Core\Config(app_config()->path())))->requireSceneScheduleOwner($identity);
    if ($recent) app_auth()->requireRecentOwner();
    return $identity;
}
function vvx_operations_field(string $name): string
{
    $value = $_POST[$name] ?? null;
    if (!is_string($value) || strlen($value) > 256) throw new InvalidArgumentException('Reload Operations and submit the complete form.');
    return $value;
}
function vvx_operations_number(string $name): int
{
    $value = vvx_operations_field($name);
    if (!preg_match('/\A[1-9][0-9]{0,3}\z/D',$value)) throw new InvalidArgumentException('Choose a whole number in the displayed range.');
    return (int)$value;
}
function vvx_operations_date(?int $at): string { return $at === null ? '—' : gmdate('M j, Y · H:i:s',$at) . ' UTC'; }
function vvx_operations_status(string $status): string
{
    return match($status) { 'queued'=>'Queued','processing'=>'In progress','complete'=>'Verified archive','failed'=>'Did not finish','held'=>'Needs owner review','deleting'=>'Retention in progress','deleted'=>'Removed by retention',default=>'Needs review' };
}
