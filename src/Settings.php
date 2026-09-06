<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpipalette;

use Config;

/**
 * Plugin settings.
 *
 * The one that matters is `itemtypes`. GLPI ships 30 types in
 * `globalsearch_types`, and each one is a separate `contains` search across
 * every displayed column — a full sweep measured ~140ms against a near-empty
 * dev database, and that cost scales with real data. A palette runs a query
 * per keystroke, so the default set is deliberately a short, high-value one;
 * anything else stays reachable through type scoping (`ticket:`, `computer:`…).
 */
final class Settings
{
    public const DEFAULTS = [
        // Searched for a bare query, in this order.
        'itemtypes'    => 'Ticket,Change,Problem,Computer,User,Software',
        // Rows fetched per itemtype per query.
        'per_type'     => 5,
        // Minimum query length before records are searched at all. Commands
        // and menu entries match from the first character (they are local).
        'min_chars'    => 2,
        // Bind Ctrl/Cmd+K. Off lets a site keep the browser default.
        'bind_ctrl_k'  => 1,
        // Also bind "/" when not typing in a field, like GitHub does.
        'bind_slash'   => 0,
        // Take record results from glpi-search when that plugin is installed
        // and indexing. Ignored entirely when it is not — this is the switch
        // for sites that have it and would rather the palette kept using
        // GLPI's own search.
        'fast_search'  => 1,
    ];

    /** @return array<string,int|string> */
    public static function all(): array
    {
        $stored = Config::getConfigurationValues(
            PLUGIN_GLPIPALETTE_CONFIG_CONTEXT,
            array_keys(self::DEFAULTS)
        );

        $out = [];
        foreach (self::DEFAULTS as $key => $default) {
            $value = $stored[$key] ?? null;
            $out[$key] = ($value === null || $value === '')
                ? $default
                : (is_int($default) ? (int) $value : (string) $value);
        }

        $out['per_type']  = max(1, min(20, (int) $out['per_type']));
        $out['min_chars'] = max(1, min(5, (int) $out['min_chars']));

        return $out;
    }

    public static function get(string $key): int|string
    {
        return self::all()[$key] ?? self::DEFAULTS[$key];
    }

    /**
     * Default itemtypes to search, filtered to those that exist and that GLPI
     * itself considers globally searchable.
     *
     * @return string[]
     */
    public static function itemtypes(): array
    {
        $out = [];
        foreach (explode(',', (string) self::get('itemtypes')) as $type) {
            $type = trim($type);
            if ($type !== '' && self::isSearchable($type)) {
                $out[] = $type;
            }
        }
        return $out;
    }

    /**
     * Every type the palette will accept as an explicit scope.
     *
     * Bounded by GLPI's own `globalsearch_types` rather than "any class with a
     * search engine": the scope name arrives from the browser, and an
     * unbounded itemtype parameter is how you turn a search box into a way to
     * probe tables the interface never meant to expose.
     *
     * @return string[]
     */
    public static function scopable(): array
    {
        global $CFG_GLPI;

        $types = $CFG_GLPI['globalsearch_types'] ?? [];
        return array_values(array_filter($types, [self::class, 'isSearchable']));
    }

    public static function isSearchable(string $itemtype): bool
    {
        global $CFG_GLPI;

        return $itemtype !== ''
            && class_exists($itemtype)
            && in_array($itemtype, $CFG_GLPI['globalsearch_types'] ?? [], true);
    }

    public static function save(array $input): void
    {
        $values = [];
        foreach (array_keys(self::DEFAULTS) as $key) {
            if (array_key_exists($key, $input)) {
                $values[$key] = $input[$key];
            }
        }
        if ($values !== []) {
            Config::setConfigurationValues(PLUGIN_GLPIPALETTE_CONFIG_CONTEXT, $values);
        }
    }
}
