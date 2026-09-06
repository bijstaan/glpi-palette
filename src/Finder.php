<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpipalette;

use CommonDBTM;
use CommonITILObject;
use Search;

/**
 * Record lookup, on top of GLPI's own global search.
 *
 * This is the same path `front/search.php` takes for the header search box —
 * `Search::getDatas()` with a `view contains …` criterion — so the palette
 * inherits GLPI's entity restrictions, profile rights and search semantics for
 * free. Reimplementing it as direct `LIKE` queries would be far faster and
 * would quietly leak rows the user is not entitled to see.
 */
final class Finder
{
    /**
     * Search a set of itemtypes for a term.
     *
     * @param string[] $itemtypes
     * @return array<int,array{itemtype:string,type_label:string,icon:string,items:array}>
     */
    public static function search(string $term, array $itemtypes, int $perType): array
    {
        $groups = [];

        // Anything glpi-search indexes comes from there: it tolerates typos,
        // matches from the first keystroke, and answers in single-digit
        // milliseconds. Everything it does not index still goes through GLPI's
        // own search below, so a type missing from that plugin's configuration
        // gets slower results rather than none.
        $indexed = array_values(array_intersect($itemtypes, FastSearch::covers()));

        if ($indexed !== []) {
            $degraded = false;
            $groups   = FastSearch::search($term, $indexed, $perType, $degraded);

            // The index could not answer — Meilisearch is unreachable, or it
            // declined the query. These types go back through GLPI's own
            // search below rather than coming back empty, because a search box
            // that finds nothing is worse than a slow one.
            if ($degraded) {
                $indexed = [];
                $groups  = [];
            }
        }

        foreach (array_diff($itemtypes, $indexed) as $itemtype) {
            if (!Settings::isSearchable($itemtype)) {
                continue;
            }

            $item = getItemForItemtype($itemtype);
            if (!$item || !$item->canView()) {
                continue;
            }

            $rows = self::query($itemtype, $term, $perType);

            if ($rows === []) {
                continue;
            }

            $groups[] = [
                'itemtype'   => $itemtype,
                'type_label' => (string) $itemtype::getTypeName(2),
                'icon'       => self::icon($itemtype),
                'items'      => $rows,
            ];
        }

        // Back into the order the caller asked for. The two sources are
        // appended one after the other, and without this a site whose indexed
        // types happen to sort later would see its groups reshuffle the moment
        // glpi-search was switched on.
        $order = array_flip(array_values($itemtypes));
        usort($groups, static fn(array $a, array $b): int
            => ($order[$a['itemtype']] ?? PHP_INT_MAX) <=> ($order[$b['itemtype']] ?? PHP_INT_MAX));

        return $groups;
    }

    /**
     * One itemtype's hits.
     *
     * @return array<int,array{id:int,title:string,subtitle:string,url:string}>
     */
    private static function query(string $itemtype, string $term, int $limit): array
    {
        $params = Search::manageParams($itemtype, ['reset' => 'reset'], false, true);
        $params['display_type'] = Search::GLOBAL_SEARCH;
        $params['start']        = 0;
        $params['list_limit']   = $limit;
        $params['criteria'][count($params['criteria'])] = [
            'field'      => 'view',      // GLPI's "search everything shown" pseudo-field
            'searchtype' => 'contains',
            'value'      => $term,
        ];

        $data = Search::getDatas($itemtype, $params);
        $rows = $data['data']['rows'] ?? [];

        $out = [];
        foreach ($rows as $row) {
            $raw = $row['raw'] ?? [];
            $id  = (int) ($raw['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            $out[] = [
                'id'       => $id,
                'title'    => self::title($itemtype, $raw, $id),
                'subtitle' => self::subtitle($itemtype, $raw),
                'url'      => self::url($itemtype, $id),
            ];

            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * The display title.
     *
     * Taken from the raw name column rather than the row's `displayname`: that
     * field is rendered HTML complete with an inline <script> qtip
     * initialiser, which has no business being piped into a JSON API and then
     * into the DOM.
     */
    private static function title(string $itemtype, array $raw, int $id): string
    {
        // Search option 1 is the name column for effectively every searchable
        // type; fall back to loading the object for the ones where it is not.
        $name = $raw['ITEM_' . $itemtype . '_1'] ?? null;

        if (is_string($name) && trim($name) !== '') {
            return trim($name);
        }

        $obj = getItemForItemtype($itemtype);
        if ($obj instanceof CommonDBTM && $obj->getFromDB($id)) {
            $friendly = (string) $obj->getFriendlyName();
            if (trim($friendly) !== '') {
                return $friendly;
            }
        }

        return sprintf('%s #%d', $itemtype::getTypeName(1), $id);
    }

    /** Status and entity, when the search happened to return them. */
    private static function subtitle(string $itemtype, array $raw): string
    {
        $bits = [];

        if (is_subclass_of($itemtype, CommonITILObject::class)) {
            $status = $raw['ITEM_' . $itemtype . '_12'] ?? null;
            if ($status !== null && $status !== '') {
                $bits[] = (string) $itemtype::getStatus((int) $status);
            }
        }

        $entity = $raw['ITEM_' . $itemtype . '_80'] ?? null;
        if (is_string($entity) && trim($entity) !== '') {
            $bits[] = trim($entity);
        }

        return implode(' · ', $bits);
    }

    private static function url(string $itemtype, int $id): string
    {
        if (method_exists($itemtype, 'getFormURLWithID')) {
            $url = (string) $itemtype::getFormURLWithID($id, false);
            if ($url !== '') {
                return $url;
            }
        }
        return '';
    }

    /** Tabler icon class GLPI already associates with the type. */
    private static function icon(string $itemtype): string
    {
        if (method_exists($itemtype, 'getIcon')) {
            $icon = (string) $itemtype::getIcon();
            if ($icon !== '') {
                return $icon;
            }
        }
        return 'ti ti-file';
    }

    /**
     * Resolve a bare "#123" / "123" jump.
     *
     * Returned only when the object exists *and* the user may view it, so the
     * palette cannot be used to probe which ids are real.
     *
     * @return array{itemtype:string,id:int,title:string,url:string}|null
     */
    public static function byId(string $itemtype, int $id): ?array
    {
        if (!Settings::isSearchable($itemtype) || $id <= 0) {
            return null;
        }

        $obj = getItemForItemtype($itemtype);

        // `can()` rather than `canViewItem()`: the latter is only half of
        // core's read contract — for most types it is `checkEntity()` and
        // nothing else, so on its own it would hand back the name of any
        // contract, document or supplier in the caller's entities whether or
        // not their profile grants the type at all. `can($id, READ)` is
        // `canView() && canViewItem()`, and it fires the ITEM_CAN hook other
        // plugins restrict through. Same gate as search() above.
        if (!($obj instanceof CommonDBTM) || !$obj->can($id, READ)) {
            return null;
        }

        return [
            'itemtype' => $itemtype,
            'id'       => $id,
            'title'    => (string) $obj->getFriendlyName(),
            'url'      => self::url($itemtype, $id),
        ];
    }
}
