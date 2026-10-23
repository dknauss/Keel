<?php
/** Run the paged network cleanup fixture in its own process. */
$argv[] = '--multisite';
require __DIR__ . '/uninstall-hibp-budget.php';
