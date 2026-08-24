<?php

/**
 * Loads Kirby and the plugin functions for the test suite.
 *
 * Kirby comes from the plugin's own vendor folder when the dev dependencies
 * are installed (composer update), or from a surrounding Kirby installation
 * when the plugin is developed inside one (site/plugins/kirby-fire).
 */

$plugin = dirname(__DIR__);

require $plugin . '/vendor/autoload.php';

if (class_exists('Kirby\Cms\App') === false) {
    $bootstrap = dirname($plugin, 3) . '/kirby/bootstrap.php';

    if (is_file($bootstrap)) {
        require $bootstrap;
    }
}

if (class_exists('Kirby\Cms\App') === false) {
    fwrite(STDERR, "Kirby not found. Run \"composer update\" to install the dev\n");
    fwrite(STDERR, "dependencies, or run the tests inside a Kirby installation.\n");
    exit(1);
}

// Kirby's Whoops handler would stay registered after every App boot,
// which PHPUnit flags as risky — same switch Kirby's own suite uses
Kirby\Cms\App::$enableWhoops = false;

// defines the fire* functions and registers the plugin
require $plugin . '/index.php';
