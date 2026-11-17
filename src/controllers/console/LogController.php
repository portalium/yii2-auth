<?php

namespace portalium\auth\controllers\console;

use portalium\auth\components\AuthLogger;
use portalium\console\Controller as ConsoleController;
use yii\console\ExitCode;

/**
 * Login log upkeep: "php portalium auth/log/purge" deletes the rows older than the auth::log_retention_days setting.
 * Writing purges now and then too, so a cron entry is optional.
 */
class LogController extends ConsoleController
{
    public function actionPurge($days = null)
    {
        $deleted = AuthLogger::purge($days === null ? null : (int) $days);
        $this->stdout($deleted . " login log rows deleted.\n");

        return ExitCode::OK;
    }
}
