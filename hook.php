<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

/**
 * No tables: the palette keeps no server-side state. Commands come from the
 * user's own menu session, records come from GLPI's search engine, and
 * "recent" lives in the browser's localStorage — deliberately, so nothing
 * about what a technician looked at is recorded server-side.
 */
function plugin_glpipalette_install()
{
    // The blend with glpi-ai's semantic search is gone; its switch would
    // otherwise sit in the config table forever, since the uninstall enumerates
    // the current defaults and this is no longer one of them.
    Config::deleteConfigurationValues(PLUGIN_GLPIPALETTE_CONFIG_CONTEXT, ['semantic']);

    return true;
}

function plugin_glpipalette_uninstall()
{
    Config::deleteConfigurationValues(
        PLUGIN_GLPIPALETTE_CONFIG_CONTEXT,
        array_keys(\GlpiPlugin\Glpipalette\Settings::DEFAULTS)
    );

    return true;
}
