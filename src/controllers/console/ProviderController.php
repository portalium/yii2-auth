<?php

namespace portalium\auth\controllers\console;

use portalium\auth\components\DomainVerifier;
use portalium\console\Controller as ConsoleController;
use yii\console\ExitCode;

/**
 * Identity provider upkeep: "php portalium auth/provider/recheck" checks the DNS-verified domains again (daily cron);
 * a domain whose TXT record is gone loses its verification.
 */
class ProviderController extends ConsoleController
{
    public function actionRecheck()
    {
        $lost = DomainVerifier::recheck();
        $this->stdout(count($lost) . " domains lost their verification" . ($lost ? ': ' . implode(', ', $lost) : '') . ".\n");

        return ExitCode::OK;
    }
}
