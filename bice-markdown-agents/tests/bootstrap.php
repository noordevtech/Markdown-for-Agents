<?php
/**
 * PHPUnit bootstrap. The tested classes are framework-free, so no WordPress
 * test suite is required — plain PHPUnit against the Composer autoloader.
 *
 * @package Bice\MarkdownAgents\Tests
 */

declare(strict_types=1);

require dirname( __DIR__ ) . '/vendor/autoload.php';
