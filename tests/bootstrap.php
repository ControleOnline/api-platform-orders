<?php

$autoloadPaths = [
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/../../../../vendor/autoload.php',
];

foreach ($autoloadPaths as $autoloadPath) {
    if (is_file($autoloadPath)) {
        require_once $autoloadPath;
        if (!class_exists('ControleOnline\\Entity\\People')) {
            require_once __DIR__ . '/Fixtures/PeopleTestDouble.php';
        }
        require_once __DIR__ . '/../../../../config/test-reporting.php';
        ensureTestReportDirectory(__DIR__ . '/../../../../var/tests/phpunit/orders');
        return;
    }
}

throw new RuntimeException('Composer autoload.php not found for orders module tests.');
