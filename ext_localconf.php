<?php

declare(strict_types=1);

defined('TYPO3') or die();

(static function (): void {
    // Hook: intercepts DataHandler "youngdogsai" localize command
    $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processCmdmapClass']['youngdogsai']
        = \YoungDogs\Translate\Hooks\TranslateHook::class;

    // Hook: intercepts per-field translation copy action
    $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processTranslateToClass']['youngdogsai']
        = \YoungDogs\Translate\Hooks\TranslateHook::class;

    // Hook: strips "[Translate to X:]" prefix from fields before database write
    $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass']['youngdogsai']
        = \YoungDogs\Translate\Hooks\TranslateHook::class;
})();
