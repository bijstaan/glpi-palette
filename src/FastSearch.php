<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpipalette;

use Plugin;

/**
 * The palette's one-way link to glpi-search.
 *
 * When that plugin is installed, active and indexing a type, the palette gets
 * its rows from Meilisearch instead of from GLPI's `contains` search: typo
 * tolerance, matching from the first keystroke, and an answer in single-digit
 * milliseconds rather than the ~140ms a full sweep costs. When it is absent,
 * inactive, or not indexing that type, nothing here does anything and the
 * palette is exactly the plugin it was before.
 *
 * This is the only file in glpi-palette that names glpi-search. Every reference
 * is a string rather than an import and every call is behind a guard, because a
 * navigation tool people reach for a hundred times a day does not get to
 * acquire a hard dependency on an optional plugin — including a fatal error
 * when somebody uninstalls one.
 *
 * Named FastSearch rather than Search because GLPI has a core class called
 * `Search`, and {@see Finder} imports it. An unqualified `Search` in a file
 * carrying that import resolves to core's, not to this namespace's — silently,
 * and only at the call.
 */
final class FastSearch
{
    private const PLUGIN    = 'glpisearch';
    private const FINDER    = 'GlpiPlugin\\Glpisearch\\Finder';
    private const SETTINGS  = 'GlpiPlugin\\Glpisearch\\Settings';

    /** Can records be fetched from the index right now? */
    public static function isAvailable(): bool
    {
        if ((int) Settings::all()['fast_search'] !== 1) {
            return false;
        }

        if (!Plugin::isPluginActive(self::PLUGIN)) {
            return false;
        }

        if (!class_exists(self::FINDER) || !class_exists(self::SETTINGS)) {
            return false;
        }

        return (bool) call_user_func([self::FINDER, 'isAvailable']);
    }

    /**
     * The itemtypes glpi-search is actually indexing.
     *
     * The palette asks for this rather than assuming, because the two plugins
     * are configured separately and a type the palette searches may be one the
     * index has never been told about. Those have to fall through to GLPI's own
     * search or they would silently vanish from results — which is worse than
     * being slow.
     *
     * @return string[]
     */
    public static function covers(): array
    {
        if (!self::isAvailable()) {
            return [];
        }

        $types = call_user_func([self::SETTINGS, 'itemtypes']);

        return is_array($types) ? $types : [];
    }

    /**
     * Grouped rows for a query, in the shape {@see Finder} already returns.
     *
     * glpi-search hands back the same structure the palette renders — itemtype,
     * label, icon, items — because both were written against the same shape.
     * Nothing is translated here on purpose: a mapping layer would be a second
     * place for the two to drift apart.
     *
     * `$degraded` comes back true when the index could not answer — the
     * backend is unreachable, or it declined the query. The palette uses it to
     * fall back to GLPI's own search for those types, which is what keeps the
     * promise that installing this plugin can only make results faster, never
     * make them disappear.
     *
     * @param string[] $itemtypes
     * @param bool|null $degraded
     * @return array<int,array<string,mixed>>
     */
    public static function search(string $term, array $itemtypes, int $perType, ?bool &$degraded = null): array
    {
        $degraded = false;

        if ($itemtypes === [] || !self::isAvailable()) {
            $degraded = true;

            return [];
        }

        $failed = false;
        $groups = call_user_func_array(
            [self::FINDER, 'search'],
            [$term, $itemtypes, $perType, &$failed]
        );

        $degraded = (bool) $failed;

        return is_array($groups) ? $groups : [];
    }
}
