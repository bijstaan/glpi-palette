<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpipalette;

use Change;
use Computer;
use Html;
use Problem;
use Session;
use Ticket;

/**
 * The non-record half of the palette: every menu destination the user can
 * reach, plus a few named actions.
 *
 * Sent once when the palette first opens and filtered in the browser. These
 * are a bounded, per-session list, so matching them locally makes the palette
 * respond to the first keystroke instead of waiting on a round-trip — the
 * record search is the only thing that has to go back to the server.
 */
final class Commands
{
    /**
     * @return array<int,array{kind:string,title:string,subtitle:string,url:string,icon:string}>
     */
    public static function all(): array
    {
        return array_merge(self::actions(), self::menu());
    }

    /**
     * Named actions. Every one is navigation — the palette opens a form, it
     * never submits anything. A launcher that mutates on Enter is one fat
     * finger away from an accident, and there is no undo for most of GLPI.
     */
    private static function actions(): array
    {
        $out = [];

        $creatable = [
            [Ticket::class,   __('New ticket')],
            [Change::class,   __('New change')],
            [Problem::class,  __('New problem')],
            [Computer::class, __('New computer')],
        ];

        foreach ($creatable as [$class, $label]) {
            if (!class_exists($class) || !$class::canCreate()) {
                continue;
            }
            $out[] = self::entry(
                'action',
                $label,
                (string) $class::getTypeName(1),
                $class::getFormURL(false) . '?id=0',
                'ti ti-plus'
            );
        }

        if (Ticket::canView()) {
            $out[] = self::entry(
                'action',
                __('My tickets'),
                (string) Ticket::getTypeName(2),
                Ticket::getSearchURL(false),
                'ti ti-ticket'
            );
        }

        $out[] = self::entry('action', __('Dashboard'), '', '/front/central.php', 'ti ti-layout-dashboard');
        $out[] = self::entry('action', __('My settings'), '', '/front/preference.php', 'ti ti-settings');
        $out[] = self::entry('action', __('Log out'), '', '/front/logout.php', 'ti ti-logout');

        return $out;
    }

    /**
     * GLPI's own menu, flattened.
     *
     * `getMenuFuzzySearchList()` reads `$_SESSION['glpimenu']`, which is built
     * per user from their profile — so this list is already filtered to what
     * they are allowed to reach, with no rights work of our own.
     */
    private static function menu(): array
    {
        Html::generateMenuSession();

        $out = [];
        foreach (Html::getMenuFuzzySearchList() as $entry) {
            $title = (string) ($entry['title'] ?? '');
            $url   = (string) ($entry['url'] ?? '');
            if ($title === '' || $url === '') {
                continue;
            }
            $out[] = self::entry('menu', $title, __('Go to'), $url, 'ti ti-arrow-right');
        }

        return $out;
    }

    private static function entry(
        string $kind,
        string $title,
        string $subtitle,
        string $url,
        string $icon
    ): array {
        return [
            'kind'     => $kind,
            'title'    => $title,
            'subtitle' => $subtitle,
            'url'      => $url,
            'icon'     => $icon,
        ];
    }
}
