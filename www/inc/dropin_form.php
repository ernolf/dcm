<?php
// SPDX-FileCopyrightText: 2026 [ernolf] Raphael Gradenwitz
// SPDX-License-Identifier: GPL-3.0-or-later

// Shared drop-in editor used by the Configuration page (phase 1) and the
// Upstream DNS page (phase 2). Each page passes the directive groups it owns
// and its edit phase; the rendering, save handling and client scripts are
// identical.

require_once __DIR__ . '/dropins.php';

// Handle a POST submission for the editable directives of $editPhase. On a
// successful write it redirects to "$self?saved=1" and exits. Returns
// ['msg' => ?[type, text], 'desired' => array]; $desired re-fills the form
// after a validation error.
function dropin_form_save(array $dirs, int $editPhase, string $self): array {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !isset($_POST['dropin_form'])) {
        return ['msg' => null, 'desired' => []];   // not this form's submission
    }
    $states  = $_POST['state'] ?? [];
    $values  = $_POST['value'] ?? [];
    $desired = [];
    foreach ($dirs as $key => $entry) {
        if (!dropin_editable($entry, $editPhase) || !empty($entry['custom'])) continue;
        $state = $states[$key] ?? 'default';
        if (!in_array($state, ['default', 'on', 'off'], true)) $state = 'default';
        $raw = (string) ($values[$key] ?? '');
        $val = (($entry['type'] ?? '') === 'list')
            ? preg_split('/\r\n|\r|\n/', $raw)            // keep lines, drop CR
            : str_replace(["\r", "\n"], '', $raw);        // single line, no injection
        $desired[$key] = ['state' => $state, 'value' => $val];
    }

    $errors = dropins_validate($dirs, $desired);
    if ($errors) {
        return ['msg' => ['err', implode(' · ', $errors)], 'desired' => $desired];
    }
    $res = dropins_apply(DNSMASQ_D, $dirs, $desired, $editPhase);
    if ($res['ok']) {
        header("Location: $self?saved=1");
        exit;
    }
    return ['msg' => ['err', implode(' · ', $res['errors'])], 'desired' => $desired];
}

// Current state for rendering: submitted values on a validation error, else disk.
function dropin_form_current(string $key, array $entry, array $merged, array $desired, bool $postErr, int $editPhase): array {
    if ($postErr && dropin_editable($entry, $editPhase) && isset($desired[$key])) {
        $d   = $desired[$key];
        $val = is_array($d['value']) ? implode("\n", $d['value']) : (string) $d['value'];
        return ['state' => $d['state'], 'input' => $val];
    }
    $st = dropin_state($key, $entry, $merged);
    if (($entry['type'] ?? '') === 'list') {
        return ['state' => $st['state'], 'input' => implode("\n", $st['values'])];
    }
    return ['state' => $st['state'], 'input' => (string) ($st['value'] ?? '')];
}

// Render the grouped directive cards for $groups (a subset of dnsmasq_groups()).
function dropin_form_render(array $dirs, array $groups, array $man, array $merged, array $desired, bool $postErr, int $editPhase): void {
    $by_group = [];
    foreach ($dirs as $key => $entry) {
        $by_group[$entry['group']][$key] = $entry;
    }

    foreach ($groups as $gid => $g):
        if (empty($by_group[$gid])) continue; ?>
<div class="card">
  <div class="card-header"><?= h($g['label']) ?></div>
  <div class="card-body">
    <div class="dropin-grid">
    <?php foreach ($by_group[$gid] as $key => $entry):
        if (!empty($entry['custom'])) continue;   // rendered by a dedicated widget
        $editable  = dropin_editable($entry, $editPhase);
        $cur       = dropin_form_current($key, $entry, $merged, $desired, $postErr, $editPhase);
        $state     = $cur['state'];
        $type      = $entry['type'] ?? 'flag';
        $hasValue  = in_array($type, ['int', 'value', 'path', 'list'], true);
        $badge     = ['default' => '#94a3b8', 'on' => '#16a34a', 'off' => '#dc2626'][$state];
        $isDefault = $state === 'default';
        $mk        = strtolower($key);
        $hasMan    = isset($man[$mk]);
        // A plain on/off switch fits when "absent = off, present = on"; the few
        // genuine three-state directives (an explicit off-form, or no on-form)
        // keep the Default/Enabled/Disabled select.
        $useSwitch = $editable && $entry['on'] !== null && ($entry['off'] ?? null) === null;
        $showValue = $hasValue || !empty($entry['optval']);
    ?>
      <div class="dropin-item<?= !$editable && $isDefault ? ' is-default' : '' ?>">
        <div class="dropin-head">
          <?php if (!$editable): ?>
            <span class="dropin-badge" style="color:<?= $badge ?>"><?= h($state) ?></span>
          <?php endif; ?>
          <span class="dropin-label"><?= h($entry['label']) ?></span>
          <?php if ($hasMan): ?>
          <button type="button" class="man-btn" title="dnsmasq manual" onclick="showMan(event, 'man-<?= h($key) ?>')">?</button>
          <?php endif; ?>
        </div>
        <div class="dropin-key">
          <?= h($key) ?>
          <?php if (!empty($entry['managed'])): ?> · managed<?php endif; ?>
          <?php if (!empty($entry['locked'])): ?> <span title="<?= h($entry['reason'] ?? '') ?>">🔒</span><?php endif; ?>
        </div>
        <div class="dropin-help"><?= h($entry['help'] ?? '') ?></div>
        <?php if (!empty($entry['recommended'])):
            $reclbl = ['on' => 'Enabled', 'off' => 'Disabled', 'default' => 'Default'][$entry['recommended']] ?? ucfirst((string) $entry['recommended']);
        ?>
        <div class="dropin-rec">recommended: <?= h($reclbl) ?></div>
        <?php endif; ?>

        <?php if ($editable): ?>
        <div class="dropin-controls">
          <?php if ($useSwitch):
            // Value switches show the dnsmasq default next to "Default" so the
            // off state's effect is visible; pure flags just read "Default".
            $offLabel = 'Default';
            if ($showValue && ($entry['default'] ?? '') !== '') {
                $offLabel .= ' (' . (string) $entry['default'] . ')';
            }
          ?>
          <label class="switch-row">
            <span class="switch">
              <input type="checkbox" name="state[<?= h($key) ?>]" value="on"<?= $state === 'on' ? ' checked' : '' ?>
                     data-off-label="<?= h($offLabel) ?>">
              <span class="switch-slider"></span>
            </span>
            <span class="switch-text"><?= $state === 'on' ? 'Enabled' : h($offLabel) ?></span>
          </label>
          <?php else: ?>
          <select name="state[<?= h($key) ?>]">
            <option value="default"<?= $state === 'default' ? ' selected' : '' ?>>Default<?php
              $def = $entry['default'] ?? '';
              if ($def !== '') echo ' (' . h((string) $def) . ')';
            ?></option>
            <?php if ($entry['on'] !== null): ?>
            <option value="on"<?= $state === 'on' ? ' selected' : '' ?>>Enabled</option>
            <?php endif; ?>
            <?php if (($entry['off'] ?? null) !== null):
              $offv = dropin_template_value($entry['off']); ?>
            <option value="off"<?= $state === 'off' ? ' selected' : '' ?>>Disabled<?php
              if ($offv !== null && $offv !== '') echo ' (' . h($offv) . ')';
            ?></option>
            <?php endif; ?>
          </select>
          <?php endif; ?>
          <?php if ($showValue && $type === 'list'): ?>
          <textarea name="value[<?= h($key) ?>]" rows="3" placeholder="one value per line"><?= h($cur['input']) ?></textarea>
          <?php elseif ($showValue):
            $isNum = $type === 'int';
            // The value box is shown only while the directive is active (see the
            // script below), so it carries no default placeholder — the user
            // always types a real value when enabling it.
          ?>
          <input type="<?= $isNum ? 'number' : 'text' ?>" name="value[<?= h($key) ?>]" value="<?= h($cur['input']) ?>"<?php
            if ($isNum) {
                echo ' inputmode="numeric" step="1"';
                if (isset($entry['min'])) echo ' min="' . (int) $entry['min'] . '"';
                if (isset($entry['max'])) echo ' max="' . (int) $entry['max'] . '"';
            }
          ?>>
          <?php endif; ?>
        </div>
        <?php else:
          // Read-only display value.
          if ($isDefault)          $disp = (string) ($entry['default'] ?? '');
          elseif ($type === 'list') { $n = substr_count($cur['input'], "\n") + ($cur['input'] !== '' ? 1 : 0); $disp = $n . ' ' . ($n === 1 ? 'entry' : 'entries'); }
          elseif ($type === 'flag') $disp = $state;
          else                      $disp = $cur['input'];
        ?>
        <div class="dropin-val"><?= h($disp) ?></div>
        <?php endif; ?>
        <?php if ($hasMan): ?>
        <div class="man-text" id="man-<?= h($key) ?>" style="display:none"><?= h($man[$mk]) ?></div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
    </div>
  </div>
</div>
    <?php endforeach;
}

// The man-page popover element plus the popover and dirty-tracking scripts.
function dropin_form_scripts(): void {
    ?>
<div id="man-pop" onclick="event.stopPropagation()">
  <div id="man-pop-title" class="man-pop-title"></div>
  <div id="man-pop-body"  class="man-pop-body"></div>
</div>
<script>
let manPop = null;
function showMan(ev, id) {
    ev.stopPropagation();
    const src = document.getElementById(id);
    if (!src) return;
    if (!manPop) manPop = document.getElementById('man-pop');
    const item  = ev.currentTarget.closest('.dropin-item');
    const label = item ? item.querySelector('.dropin-label').textContent : '';
    document.getElementById('man-pop-title').textContent = label;
    document.getElementById('man-pop-body').textContent  = src.textContent;

    manPop.style.display = 'block';
    const r = ev.currentTarget.getBoundingClientRect();
    let left = Math.min(r.right - manPop.offsetWidth, window.innerWidth - manPop.offsetWidth - 8);
    if (left < 8) left = 8;
    let top = r.bottom + 6;
    if (top + manPop.offsetHeight > window.innerHeight - 8) {
        top = Math.max(8, r.top - manPop.offsetHeight - 6);
    }
    manPop.style.left = left + 'px';
    manPop.style.top  = top + 'px';
}
function hideMan() { if (manPop) manPop.style.display = 'none'; }
document.addEventListener('click', hideMan);
document.addEventListener('keydown', e => { if (e.key === 'Escape') hideMan(); });
window.addEventListener('scroll', hideMan, true);

// A value box only makes sense while its directive is active. Hide it whenever
// the control is in a state where the value is fixed by dnsmasq's default or the
// off form: a switch that is off, or a three-state select on Default/Disabled.
// There is no placeholder, so enabling always asks for a fresh value; entering 0
// in cache-size writes cache-size=0, which reads back as Disabled on next load.
document.querySelectorAll('.dropin-controls').forEach(box => {
    const ctl = box.querySelector('input[type="checkbox"][name^="state["], select[name^="state["]');
    if (!ctl) return;
    const value = box.querySelector('[name^="value["]');
    const text  = box.querySelector('.switch-text');
    const isOn  = () => ctl.tagName === 'SELECT' ? ctl.value === 'on' : ctl.checked;
    const sync  = focus => {
        const on = isOn();
        if (text) text.textContent = on ? 'Enabled' : (ctl.dataset.offLabel || 'Default');
        if (value) {
            value.style.display = on ? '' : 'none';
            if (on && focus) value.focus();
        }
    };
    ctl.addEventListener('change', () => sync(true));
    sync(false);
});

// Enable "Save changes" only while the form differs from its loaded state.
(function () {
    const form = document.querySelector('form');
    if (!form) return;
    const saves    = Array.from(document.querySelectorAll('.js-save'));
    const controls = Array.from(form.querySelectorAll('select, input, textarea'));
    const val      = c => c.type === 'checkbox' ? (c.checked ? '1' : '0') : c.value;
    const baseline = controls.map(val);
    function refresh() {
        const dirty = controls.some((c, i) => val(c) !== baseline[i]);
        saves.forEach(b => b.disabled = !dirty);
    }
    form.addEventListener('input',  refresh);
    form.addEventListener('change', refresh);
    refresh();
})();
</script>
    <?php
}
