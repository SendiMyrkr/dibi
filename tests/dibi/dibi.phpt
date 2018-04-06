<?php

/**
 * @dataProvider ../databases.ini
 */

use Tester\Assert;

require __DIR__ . '/bootstrap.php';

dibi::connect($config);
dibi::loadFile(__DIR__ . "/data/$config[system].sql");
dibi::query('INSERT INTO products', [
	'title' => 'Test product',
]);
Assert::same(1, dibi::getAffectedRows());
