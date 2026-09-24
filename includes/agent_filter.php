<?php
// Insurance-agent filter shared by Client Records and Renewal Tracking (owner's request, 2026-09-24).
// A user who is an insurance agent opens these lists on THEIR OWN clients ("My Clients") and can switch to
// another agent, "Unassigned" or everyone. With no ?agent= in the URL that means "My Clients" for an agent,
// and everyone for a user who is not an agent (the hidden account).
// Needs config/access.php and includes/icons.php; the page also loads assets/css/shared/agent_filter.css and
// assets/js/shared/agent_filter.js (see render_agent_filter()).

/**
 * The filter for this request. $enabled = false (mechanics) means no filter at all — always "all".
 * value: 'all' | 'none' (no agent) | an agent's user_id as a string.
 */
function agent_filter_state(mysqli $conn, bool $enabled = true): array
{
    $me     = (int)($_SESSION['user_id'] ?? 0);
    $active = $enabled ? insurance_agents($conn) : [];
    $names  = [];   // user_id => display name
    $opts   = [];   // user_id => label for the form's <select>
    if ($enabled) {
        foreach ($active as $id => $a) { $names[$id] = $a['full_name']; $opts[$id] = agent_option_label($a); }
        // The signed-in agent's own entry reads "My Clients" and sits at the top, right under "All Agents"
        if (isset($opts[$me])) $opts = [$me => 'My Clients'] + $opts;
        // Agents who still hold clients but can no longer be picked (deactivated / role changed) stay filterable
        $held = $conn->query("SELECT DISTINCT u.user_id, CASE WHEN u.is_hidden = 1 THEN 'System Administrator' ELSE u.full_name END AS name
                              FROM clients c INNER JOIN users u ON u.user_id = c.agent_id WHERE c.deleted_at IS NULL ORDER BY name");
        foreach ($held->fetch_all(MYSQLI_ASSOC) as $u) {
            $uid = (int)$u['user_id'];
            if (!isset($opts[$uid])) { $names[$uid] = $u['name']; $opts[$uid] = $u['name'] . ' (former agent)'; }
        }
    }
    // The same list for the themed menu: [value, shown name, name for the avatar, tag, photo]
    $menu = [];
    foreach ($opts as $id => $_) {
        $a = $active[$id] ?? null;
        $menu[] = [
            'value' => (string)$id,
            'name'  => $id === $me ? 'My Clients' : $names[$id],
            'who'   => $names[$id],
            'tag'   => $id === $me ? '' : ($a === null ? 'Former agent' : ($a['role'] === 'super_admin' ? 'Owner' : '')),
            'photo' => $a['profile_photo'] ?? null,
        ];
    }

    $default = isset($active[$me]) ? (string)$me : 'all';
    $get     = $_GET['agent'] ?? $default;
    $value   = is_scalar($get) ? (string)$get : 'all';
    if (!$enabled || !($value === 'all' || $value === 'none' || (ctype_digit($value) && isset($opts[(int)$value])))) {
        $value = 'all';
    }
    return ['enabled' => $enabled, 'me' => $me, 'value' => $value, 'default' => $default, 'names' => $names, 'opts' => $opts, 'menu' => $menu];
}

/** SQL for the current choice on a client's agent column: " AND c.agent_id = 12", " AND c.agent_id IS NULL" or "". */
function agent_filter_sql(array $af, string $col): string
{
    return match ($af['value']) {
        'all'   => '',
        'none'  => " AND $col IS NULL",
        default => " AND $col = " . (int)$af['value'],
    };
}

/** Is the list showing the signed-in user's own clients / someone else's? */
function agent_filter_is_mine(array $af): bool { return $af['value'] === (string)$af['me']; }
function agent_filter_is_other(array $af): bool { return ctype_digit($af['value']) && !agent_filter_is_mine($af); }

/** Name of the chosen agent ('' for All / Unassigned). */
function agent_filter_name(array $af): string
{
    return ctype_digit($af['value']) ? (string)($af['names'][(int)$af['value']] ?? '') : '';
}

/** What the button shows: "All Agents", "Unassigned", "My Clients" or the agent's name. */
function agent_filter_label(array $af): string
{
    return match (true) {
        $af['value'] === 'all'  => 'All Agents',
        $af['value'] === 'none' => 'Unassigned',
        agent_filter_is_mine($af) => 'My Clients',
        default => agent_filter_name($af),
    };
}

/**
 * The filter: a hidden <select name="agent"> (the real form field) plus the themed button and menu, which only
 * set it and submit. Put it inside the page's GET form, so the other filters travel along.
 */
function render_agent_filter(array $af, string $base_path, string $title = 'Show the clients of an insurance agent'): void
{
    if (!$af['enabled']) return;
    $initials_of = fn(string $n) => substr(implode('', array_map(fn($w) => strtoupper($w[0] ?? ''), explode(' ', trim($n)))), 0, 2);
    // One menu row. $avatar is trusted HTML built below; every text value is escaped here.
    $row = function (string $value, string $name, string $avatar, string $tag = '') use ($af) {
        $on = $af['value'] === $value;
        echo '<button type="button" class="cl-agent-opt' . ($on ? ' is-selected' : '') . '" role="option" aria-selected="' . ($on ? 'true' : 'false') . '" data-value="' . htmlspecialchars($value) . '">'
           . $avatar . '<span class="cl-agent-opt-name">' . htmlspecialchars($name) . '</span>'
           . ($tag !== '' ? '<span class="cl-agent-tag">' . htmlspecialchars($tag) . '</span>' : '')
           . ($on ? '<span class="cl-agent-check">' . icon('check', 14) . '</span>' : '')
           . '</button>';
    };
    $person = fn(array $m) => '<span class="cl-agent-av">' . (!empty($m['photo'])
        ? '<img src="' . htmlspecialchars($base_path) . 'uploads/avatars/' . htmlspecialchars($m['photo']) . '" alt="" loading="lazy"/>'
        : htmlspecialchars($initials_of($m['who']))) . '</span>';
    ?>
    <div class="cl-agent-wrap" id="agent-filter-wrap">
      <select name="agent" id="agent-filter" class="cl-agent-native" tabindex="-1" aria-hidden="true">
        <option value="all" <?= $af['value'] === 'all' ? 'selected' : '' ?>>All Agents</option>
        <?php foreach ($af['opts'] as $id => $label): ?>
        <option value="<?= $id ?>" <?= $af['value'] === (string)$id ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
        <?php endforeach; ?>
        <option value="none" <?= $af['value'] === 'none' ? 'selected' : '' ?>>Unassigned</option>
      </select>
      <button type="button" class="cl-agent-btn" id="agent-filter-btn" aria-haspopup="listbox" aria-expanded="false" aria-controls="agent-filter-menu" title="<?= htmlspecialchars($title) ?>">
        <?= icon('user', 15) ?>
        <span class="cl-agent-btn-label"><?= htmlspecialchars(agent_filter_label($af)) ?></span>
        <span class="cl-agent-chev"><?= icon('chevron-down', 12) ?></span>
      </button>
      <div class="cl-agent-menu" id="agent-filter-menu" role="listbox" aria-label="Insurance agent" hidden>
        <div class="cl-agent-menu-head">Insurance Agent</div>
        <?php
        $row('all', 'All Agents', '<span class="cl-agent-av is-icon">' . icon('users', 13) . '</span>');
        $rest = $af['menu'];
        if ($rest && $rest[0]['value'] === (string)$af['me']) { $m = array_shift($rest); $row($m['value'], $m['name'], $person($m)); }
        if ($rest) echo '<div class="cl-agent-sep"></div>';
        foreach ($rest as $m) $row($m['value'], $m['name'], $person($m), $m['tag']);
        echo '<div class="cl-agent-sep"></div>';
        $row('none', 'Unassigned', '<span class="cl-agent-av is-none">' . icon('user', 12) . '</span>');
        ?>
      </div>
    </div>
    <?php
}
