<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */
/**
 * GLPI Command Palette — a GitHub-style Ctrl/Cmd+K launcher.
 *
 * One box that reaches everything: records via GLPI's global search, every
 * menu destination the user is allowed to see, and a handful of commands.
 *
 * Complements rather than replaces core's "Find menu" (Ctrl+Alt+G), which
 * fuzzy-matches *menu entries only* — it cannot find a ticket by its title or
 * a machine by its name. Both can be bound at once; they do different jobs.
 */

define('PLUGIN_GLPIPALETTE_VERSION', '0.1.0');
define('PLUGIN_GLPIPALETTE_MIN_GLPI', '10.0');
define('PLUGIN_GLPIPALETTE_CONFIG_CONTEXT', 'plugin:glpipalette');

function plugin_init_glpipalette()
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS['csrf_compliant']['glpipalette'] = true;
    $PLUGIN_HOOKS['config_page']['glpipalette']    = 'front/config.php';

    // Technicians only. Registering the assets conditionally (rather than
    // letting the JS bail at runtime) means the helpdesk interface never has
    // its Ctrl+K taken away for a palette it is not allowed to query.
    if (Session::getCurrentInterface() === 'central') {
        $PLUGIN_HOOKS['add_javascript']['glpipalette'] = 'js/palette.js';
        $PLUGIN_HOOKS['add_css']['glpipalette']        = 'css/palette.css';
    }
}

function plugin_version_glpipalette()
{
    return [
        'name'         => 'GLPI Command Palette',
        'version'      => PLUGIN_GLPIPALETTE_VERSION,
        'author'       => 'Bijstaan',
        'license'      => 'GPL-3.0-or-later',
        'homepage'     => 'https://github.com/bijstaan/glpi-palette',
        'requirements' => ['glpi' => ['min' => PLUGIN_GLPIPALETTE_MIN_GLPI]],
    ];
}

function plugin_glpipalette_check_prerequisites()
{
    return true;
}

function plugin_glpipalette_check_config($verbose = false)
{
    return true;
}
