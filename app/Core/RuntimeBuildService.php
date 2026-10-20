<?php
declare(strict_types=1);

namespace App\Core;

require_once __DIR__ . '/RuntimeInventoryService.php';

/** Existing integrations share the current inventory verifier. */
final class RuntimeBuildService extends RuntimeInventoryService
{
}
