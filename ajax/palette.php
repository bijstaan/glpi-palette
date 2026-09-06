<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

/**
 * Command palette backend.
 *
 * Two actions: `bootstrap` hands over the local-match material (commands and
 * menu destinations) once per palette session, and `search` runs GLPI's global
 * search for records.
 *
 * No CSRF check here. GLPI 11's CheckCsrfListener already validated the
 * request before this script ran, taking the token from the
 * `X-Glpi-Csrf-Token` header and *preserving* it — which is what lets the
 * palette fire a request per keystroke without draining the session's token
 * pool. Re-checking would reject requests whose token the kernel consumed.
 */

use GlpiPlugin\Glpipalette\Commands;
use GlpiPlugin\Glpipalette\Finder;
use GlpiPlugin\Glpipalette\Settings;

header('Content-Type: application/json; charset=UTF-8');
Html::header_nocache();

$respond = static function (array $payload, int $status = 200): never {
    http_response_code($status);
    echo json_encode($payload, JSON_THROW_ON_ERROR);
    exit;
};

if ((int) Session::getLoginUserID() <= 0) {
    $respond(['error' => 'unauthenticated'], 401);
}

// Technician interface only, matching where the assets are registered.
if (Session::getCurrentInterface() !== 'central') {
    $respond(['error' => 'forbidden'], 403);
}

$settings = Settings::all();
$action   = (string) ($_POST['action'] ?? $_GET['action'] ?? '');

if ($action === 'bootstrap') {
    $scopes = [];
    foreach (Settings::scopable() as $itemtype) {
        $scopes[] = [
            'itemtype' => $itemtype,
            'label'    => (string) $itemtype::getTypeName(2),
            // Lowercased type name is what the user will actually type
            // ("ticket:", "computer:"), so hand it over rather than making
            // the browser guess at localised names.
            'keyword'  => mb_strtolower($itemtype),
        ];
    }

    $respond([
        'commands'  => Commands::all(),
        'scopes'    => $scopes,
        'itemtypes' => Settings::itemtypes(),
        'min_chars' => (int) $settings['min_chars'],
        'bindings'  => [
            'ctrl_k' => (int) $settings['bind_ctrl_k'] === 1,
            'slash'  => (int) $settings['bind_slash'] === 1,
        ],
    ]);
}

if ($action !== 'search') {
    $respond(['error' => 'unknown_action'], 400);
}

$term  = trim((string) ($_POST['q'] ?? ''));
$scope = trim((string) ($_POST['scope'] ?? ''));

if ($term === '') {
    $respond(['query' => '', 'scope' => null, 'jump' => null, 'groups' => []]);
}

// An explicit scope must be one GLPI already publishes as globally
// searchable; the value arrives from the browser and is used as a class name.
if ($scope !== '' && !in_array($scope, Settings::scopable(), true)) {
    $respond(['error' => 'invalid_scope'], 400);
}

$itemtypes = $scope !== '' ? [$scope] : Settings::itemtypes();

// "#41" / "41" offers a direct jump alongside the text results. Resolved
// server-side because it must be filtered by canViewItem() — otherwise the
// palette would confirm which ids exist to someone with no rights to them.
$jump = null;
if (preg_match('/^#?(\d+)$/', $term, $m)) {
    $target = $scope !== '' ? $scope : 'Ticket';
    $jump   = Finder::byId($target, (int) $m[1]);
}

// Below the minimum length, records are skipped entirely — a one-character
// `contains` across every column of six itemtypes is all cost and no signal.
// The jump still resolves, since an exact id is unambiguous.
if (mb_strlen($term) < (int) $settings['min_chars']) {
    $respond([
        'query'  => $term,
        'scope'  => $scope !== '' ? $scope : null,
        'jump'   => $jump,
        'groups' => [],
        'short'  => true,
    ]);
}

$respond([
    'query'  => $term,
    'scope'  => $scope !== '' ? $scope : null,
    'jump'   => $jump,
    'groups' => Finder::search($term, $itemtypes, (int) $settings['per_type']),
]);
