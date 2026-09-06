<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

require_once(__DIR__ . '/../../../front/_check_webserver_config.php');

use GlpiPlugin\Glpipalette\Settings;

Session::checkRight('config', READ);

if (!empty($_POST['update'])) {
    // GLPI 11's CheckCsrfListener already validated and consumed the token.
    Session::checkRight('config', UPDATE);

    Settings::save([
        'itemtypes'   => implode(',', (array) ($_POST['itemtypes'] ?? [])),
        'per_type'    => (int) ($_POST['per_type'] ?? 0),
        'min_chars'   => (int) ($_POST['min_chars'] ?? 0),
        'bind_ctrl_k' => !empty($_POST['bind_ctrl_k']) ? '1' : '0',
        'bind_slash'  => !empty($_POST['bind_slash']) ? '1' : '0',
        'fast_search' => !empty($_POST['fast_search']) ? '1' : '0',
    ]);

    Session::addMessageAfterRedirect(__s('Settings saved.', 'glpipalette'));
    Html::back();
}

Html::header(__('Command Palette', 'glpipalette'), $_SERVER['PHP_SELF'], 'config', 'plugins');

$cfg      = Settings::all();
$selected = Settings::itemtypes();
$e        = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

// Read-only visitors keep the page but lose the button.
//
// READ opens this page and UPDATE saves it, and the two are separately
// grantable — so a profile can legitimately arrive here unable to change
// anything. Rendering the form as though they could, and answering Save with an
// access-denied page, wastes the work they just did explaining nothing.
$can_edit = Session::haveRight('config', UPDATE);

// Helper text on the dark palette is handled by palette.css (see its
// "dark theme" section): the inline property override that used to live here
// was a measured no-op for `.text-muted` — core declares it with !important,
// which no property override beats — and its 28% formula measured under the
// 4.5:1 floor for `.form-text` on auror_dark anyway. palette.css redefines
// the `--tblr-muted` / `--tblr-secondary-color` VARIABLES inside
// `.glpipalette-config` instead.
echo "<div class='container-fluid glpipalette-config' style='max-width:960px'>";

if (!$can_edit) {
    echo "<div class='alert alert-info py-2'>"
       . __s('Read only: you can see these settings but not change them.', 'glpipalette')
       . '</div>';
}
echo "<form method='post'>";
echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);

echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'>"
    . __s('Searched by default', 'glpipalette') . '</h3></div>';
echo "<div class='card-body'>";
echo '<p class="text-muted">'
    . __s(
        'Each itemtype is a separate search across every displayed column, run on every keystroke — so keep this list short. Everything else stays reachable by typing a scope, e.g. "printer: hp".',
        'glpipalette'
    )
    . '</p>';
echo "<div class='row'>";
foreach (Settings::scopable() as $itemtype) {
    $checked = in_array($itemtype, $selected, true) ? "checked='checked'" : '';
    echo "<div class='col-md-4'><label class='form-check'>";
    echo "<input type='checkbox' class='form-check-input' name='itemtypes[]' value='"
        . $e($itemtype) . "' $checked>";
    echo "<span class='form-check-label'>" . $e($itemtype::getTypeName(2)) . '</span>';
    echo '</label></div>';
}
echo '</div></div></div>';

echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'>"
    . __s('Behaviour', 'glpipalette') . '</h3></div>';
echo "<div class='card-body'><div class='row'>";
echo "<div class='col-md-6 mb-3'><label class='form-label'>"
    . __s('Results per itemtype', 'glpipalette') . '</label>'
    . "<input type='number' min='1' max='20' class='form-control' name='per_type' value='"
    . $e($cfg['per_type']) . "'></div>";
echo "<div class='col-md-6 mb-3'><label class='form-label'>"
    . __s('Minimum characters before searching records', 'glpipalette') . '</label>'
    . "<input type='number' min='1' max='5' class='form-control' name='min_chars' value='"
    . $e($cfg['min_chars']) . "'></div>";
echo '</div>';

$ck = ((int) $cfg['bind_ctrl_k']) === 1 ? "checked='checked'" : '';
$sl = ((int) $cfg['bind_slash']) === 1 ? "checked='checked'" : '';
echo "<label class='form-check'><input type='checkbox' class='form-check-input' name='bind_ctrl_k' value='1' $ck>";
echo "<span class='form-check-label'>" . __s('Open with Ctrl/Cmd+K', 'glpipalette') . '</span></label>';
echo "<label class='form-check'><input type='checkbox' class='form-check-input' name='bind_slash' value='1' $sl>";
echo "<span class='form-check-label'>"
    . __s('Also open with "/" when not typing in a field', 'glpipalette') . '</span></label>';
echo "<p class='text-muted mt-2 mb-0'>"
    . __s("GLPI's own \"Find menu\" (Ctrl+Alt+G) is unaffected and keeps working alongside this.", 'glpipalette')
    . '</p>';
echo '</div></div>';

// ------------------------------------------------------------- fast search
// Shown whether or not glpi-search is installed, and honest about which. A
// setting that vanishes when a plugin is missing leaves an administrator
// wondering whether they imagined it.
$fast_ready = GlpiPlugin\Glpipalette\FastSearch::isAvailable();
$fast_here  = Plugin::isPluginActive('glpisearch');

echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'>"
    . __s('Faster record search', 'glpipalette') . '</h3></div><div class="card-body">';

$fs = ((int) $cfg['fast_search']) === 1 ? "checked='checked'" : '';
echo "<label class='form-check'><input type='checkbox' class='form-check-input' name='fast_search' value='1' $fs>";
echo "<span class='form-check-label'>"
    . __s('Use the search plugin\'s index when it is available', 'glpipalette') . '</span></label>';
echo "<div class='form-text'>"
    . __s('GLPI\'s own search matches words exactly, and a palette runs a query per keystroke — a '
        . 'full sweep of six itemtypes costs around 140ms before any of it reaches the screen. '
        . 'With glpi-search installed, those types are answered from its index instead: typos '
        . 'tolerated, matching from the first character, and single-digit milliseconds. Types it '
        . 'is not indexing still go through GLPI\'s search, so nothing disappears from the '
        . 'results either way.', 'glpipalette')
    . '</div>';

echo "<div class='mt-2'>";
if ($fast_ready) {
    echo "<span class='badge bg-green-lt'>" . __s('available', 'glpipalette') . '</span>';
} elseif ($fast_here) {
    echo "<span class='badge bg-orange-lt'>"
        . __s('the search plugin is installed but not indexing yet', 'glpipalette') . '</span>';
} else {
    echo "<span class='badge bg-secondary-lt'>"
        . __s('the search plugin is not installed', 'glpipalette') . '</span>';
}
echo '</div>';

echo '</div></div>';

if ($can_edit) {
    echo "<div class='text-end mb-4'><button type='submit' name='update' value='1' class='btn btn-primary'>"
        . __s('Save', 'glpipalette') . '</button></div>';
}
echo '</form></div>';

Html::footer();
