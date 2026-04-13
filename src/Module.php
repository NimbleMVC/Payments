<?php

namespace NimblePHP\Payments;

use NimblePHP\Framework\Module\Interfaces\ModuleInterface;
use NimblePHP\Framework\Module\Interfaces\ModuleUpdateInterface;
use NimblePHP\Framework\Kernel;
use NimblePHP\Migrations\Migrations;
use Throwable;

class Module implements ModuleInterface, ModuleUpdateInterface
{

    public function getName(): string
    {
        return 'NimblePHP Payments';
    }

    public function register(): void
    {
        return;
    }

    /**
     * @throws Throwable
     */
    public function onUpdate(): void
    {
        $projectPath = isset(Kernel::$projectPath) ? Kernel::$projectPath : false;

        $migrations = new Migrations(
            projectPath: $projectPath,
            migrationsPath: __DIR__ . '/../migrations',
            migrationsGroup: 'nimblephp-payments'
        );
        $migrations->runMigrations();
    }

}
