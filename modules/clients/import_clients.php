<?php
require_once __DIR__ . "/../../config/session.php";
require_once '../../config/db.php';
require_once '../../config/validators.php';
require_once '../../config/access.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'super_admin'])) {
    header("Location: ../../auth/login.php");
    exit;
}

/*
 * Bulk client import (owner's staff are encoding ~1,000 existing clients, 2026-09-25).
 * Upload a CSV -> every row is checked the way Add Client checks it, plus duplicate plates against the system
 * AND within the file -> preview -> only the good rows are saved. CSV only: the server has no zip extension, so
 * an .xlsx cannot be read — Excel's "Save As > CSV UTF-8" is the way in. One row = one vehicle; rows with the
 * same name and the same address (or contact number) become one client with several vehicles.
 */

const IMPORT_MAX_ROWS  = 1000;
const IMPORT_MAX_BYTES = 2097152;   // 2 MB — about ten times what 1,000 rows need
const IMPORT_TTL       = 7200;      // a preview nobody imported is dropped after 2 hours

// key => [column name in the template, other accepted spellings (lowercase letters/digits only), required, max length].
// Max lengths follow the DB columns where those are shorter than the form limits (email 100, make/model 50, color 30).
const IMPORT_COLUMNS = [
    'full_name'      => ['Full Name',      ['name', 'client', 'clientname', 'clientsname', 'pangalan'], true, MAX_NAME],
    'contact_number' => ['Contact Number', ['contact', 'contactno', 'phone', 'phoneno', 'phonenumber', 'mobile', 'mobileno', 'mobilenumber', 'cellphone', 'cellphonenumber', 'cpno', 'cpnumber'], false, 20],
    'email'          => ['Email',          ['emailaddress'], false, 100],
    'facebook_name'  => ['Facebook Name',  ['facebook', 'fb', 'fbname', 'fbaccount'], false, MAX_FACEBOOK],
    'address'        => ['Address',        ['completeaddress', 'homeaddress', 'tirahan'], true, MAX_ADDRESS],
    'plate_number'   => ['Plate Number',   ['plate', 'plateno'], true, MAX_PLATE],
    'make'           => ['Make',           ['brand', 'vehiclemake'], true, 50],
    'model'          => ['Model',          ['vehiclemodel'], true, 50],
    'year_model'     => ['Year Model',     ['year', 'modelyear'], true, 4],
    'color'          => ['Color',          ['colour'], false, 30],
    'motor_number'   => ['Engine No.',     ['engine', 'enginenumber', 'motor', 'motorno', 'motornumber'], false, MAX_MOTOR_SN],
    'serial_number'  => ['Chassis No.',    ['chassis', 'chassisnumber', 'serial', 'serialno', 'serialnumber'], false, MAX_MOTOR_SN],
    'agent'          => ['Agent',          ['insuranceagent', 'agentname'], false, MAX_NAME],
];

/** Excel cells can carry line breaks, double or non-breaking spaces. */
function imp_clean(string $s): string {
    return trim(preg_replace('/[\s\x{00A0}\x{200B}\x{FEFF}]+/u', ' ', $s));
}
function imp_upper(string $s): string { return mb_strtoupper(imp_clean($s)); }
/** "ABC-1234", "abc1234" and "ABC 1234" are the same plate. */
function imp_plate_key(string $p): string { return preg_replace('/[^A-Z0-9]/', '', strtoupper($p)); }
function imp_header_norm(string $s): string { return preg_replace('/[^a-z0-9]/', '', strtolower(preg_replace('/\(.*?\)/', '', $s))); }

function imp_header_key(string $label): ?string {
    static $lookup = null;
    if ($lookup === null) {
        $lookup = [];
        foreach (IMPORT_COLUMNS as $key => [$title, $aliases]) {
            foreach (array_merge([imp_header_norm($title)], $aliases) as $a) $lookup[$a] = $key;
        }
    }
    return $lookup[imp_header_norm($label)] ?? null;
}

/** File contents -> ['rows' => [['line' => spreadsheet row number, 'cells' => [key => text]], …]] or ['error' => message]. */
function imp_read_csv(string $raw): array {
    if (strncmp($raw, "PK\x03\x04", 4) === 0 || strncmp($raw, "\xD0\xCF\x11\xE0", 4) === 0) {
        return ['error' => 'That is an Excel workbook, not a CSV file. In Excel choose File > Save As > "CSV UTF-8 (Comma delimited)", then upload the new file.'];
    }
    if (strncmp($raw, "\xEF\xBB\xBF", 3) === 0)  $raw = substr($raw, 3);
    elseif (strncmp($raw, "\xFF\xFE", 2) === 0) $raw = mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16LE');   // Excel "Unicode Text"
    elseif (strncmp($raw, "\xFE\xFF", 2) === 0) $raw = mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16BE');
    if (!mb_check_encoding($raw, 'UTF-8')) $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');   // Excel's plain "CSV (Comma delimited)" (keeps Ñ)
    if (trim($raw) === '') return ['error' => 'The file is empty.'];

    // Excel writes ";" instead of "," under some regional settings, and tabs in "Text" files — go by the header line
    $first = preg_split('/\R/', $raw, 2)[0];
    $delim = ',';
    foreach ([';', "\t"] as $d) if (substr_count($first, $d) > substr_count($first, $delim)) $delim = $d;

    $fh = fopen('php://temp', 'r+');
    fwrite($fh, $raw);
    rewind($fh);
    $map = [];
    foreach (fgetcsv($fh, null, $delim, '"', '') ?: [] as $i => $label) {
        $key = imp_header_key((string)$label);
        if ($key !== null && !isset($map[$key])) $map[$key] = $i;
    }
    $missing = [];
    foreach (IMPORT_COLUMNS as $key => [$title, , $required]) if ($required && !isset($map[$key])) $missing[] = $title;
    if ($missing) {
        fclose($fh);
        return ['error' => 'Missing column' . (count($missing) > 1 ? 's' : '') . ': ' . implode(', ', $missing) . '. The first row of the file must hold the column names — download the template to see them.'];
    }

    $rows = [];
    $line = 1;
    while (($cells = fgetcsv($fh, null, $delim, '"', '')) !== false) {
        $line++;
        $row = [];
        foreach ($map as $key => $i) $row[$key] = imp_clean((string)($cells[$i] ?? ''));
        if (implode('', $row) === '') continue;   // blank row
        if (count($rows) >= IMPORT_MAX_ROWS) {
            fclose($fh);
            return ['error' => 'The file has more than ' . number_format(IMPORT_MAX_ROWS) . ' rows. Split it into smaller files and import them one at a time.'];
        }
        $rows[] = ['line' => $line, 'cells' => $row];
    }
    fclose($fh);
    return $rows ? ['rows' => $rows] : ['error' => 'The file has the column names but no client rows.'];
}

/** Excel drops the leading 0 (9171234567), shows +63, or turns long numbers into 9.17E+09. Returns [number, error]. */
function imp_phone(string $v): array {
    if ($v === '') return ['', ''];
    if (preg_match('/\dE\+?\d+$/i', $v)) {
        return ['', 'Contact number "' . $v . '" was changed into a scientific number by Excel. Format that column as Text, retype the number and save again.'];
    }
    $d = preg_replace('/\D/', '', $v);
    if (strlen($d) === 10 && $d[0] === '9')                   $d = '0' . $d;
    elseif (strlen($d) === 12 && str_starts_with($d, '639'))  $d = '0' . substr($d, 2);
    return preg_match('/^09\d{9}$/', $d) === 1 ? [$d, ''] : ['', 'Contact number "' . $v . '" is not a PH mobile number (09XXXXXXXXX).'];
}

/** "AGENT NAME" / "USERNAME" => user_id, or -1 when two agents share it. Only real agents: never mechanics or the hidden account. */
function imp_agent_lookup(mysqli $conn, array $agents): array {
    $lookup = [];
    $add = function (string $key, int $id) use (&$lookup) {
        $key = imp_upper($key);
        if ($key !== '') $lookup[$key] = isset($lookup[$key]) && $lookup[$key] !== $id ? -1 : $id;
    };
    foreach ($agents as $id => $a) $add($a['full_name'], (int)$id);
    if ($agents) {
        $res = $conn->query("SELECT user_id, username FROM users WHERE user_id IN (" . implode(',', array_map('intval', array_keys($agents))) . ")");
        while ($u = $res->fetch_assoc()) $add($u['username'], (int)$u['user_id']);
    }
    return $lookup;
}

/** One row's own checks (what Add Client checks). */
function imp_check_row(array $c, array $agent_lookup, int $default_agent): array {
    $errors = [];
    $long   = [];
    foreach (IMPORT_COLUMNS as $key => [$title, , , $max]) {
        if ($key !== 'contact_number' && $key !== 'year_model' && mb_strlen($c[$key] ?? '') > $max) {
            $errors[] = $title . ' is too long (max ' . $max . ' characters).';
            $long[$key] = true;
        }
    }
    $d = [
        'full_name'     => mb_strtoupper($c['full_name'] ?? ''),
        'email'         => $c['email'] ?? '',
        'facebook_name' => $c['facebook_name'] ?? '',
        'address'       => $c['address'] ?? '',
        'plate_number'  => mb_strtoupper($c['plate_number'] ?? ''),
        'make'          => $c['make'] ?? '',
        'model'         => $c['model'] ?? '',
        'year_model'    => 0,
        'color'         => $c['color'] ?? '',
        'motor_number'  => $c['motor_number'] ?? '',
        'serial_number' => $c['serial_number'] ?? '',
    ];
    if ($d['full_name'] === '')                                   $errors[] = 'Full Name is empty.';
    elseif (!isset($long['full_name']) && !validate_name($d['full_name'])) $errors[] = 'Full Name may only have letters, spaces, dots, hyphens and apostrophes.';
    [$d['contact_number'], $phone_error] = imp_phone($c['contact_number'] ?? '');
    if ($phone_error !== '')                                      $errors[] = $phone_error;
    if ($d['email'] !== '' && !isset($long['email']) && !validate_email($d['email'])) $errors[] = 'Email "' . $d['email'] . '" is not a valid email address.';
    if ($d['address'] === '')                                     $errors[] = 'Address is empty.';
    if ($d['plate_number'] === '')                                $errors[] = 'Plate Number is empty.';
    elseif (!isset($long['plate_number']) && !validate_plate($d['plate_number'])) $errors[] = 'Plate Number may only have letters, numbers, spaces and hyphens.';
    if ($d['make'] === '')                                        $errors[] = 'Make is empty.';
    if ($d['model'] === '')                                       $errors[] = 'Model is empty.';
    $year = $c['year_model'] ?? '';
    if ($year === '')                                             $errors[] = 'Year Model is empty.';
    elseif (!preg_match('/^\d{4}$/', $year) || !validate_year((int)$year)) $errors[] = 'Year Model "' . $year . '" must be a year from 1960 to ' . ((int)date('Y') + 1) . '.';
    else                                                          $d['year_model'] = (int)$year;

    $agent_id = $default_agent;
    $agent    = imp_upper($c['agent'] ?? '');
    if ($agent !== '') {
        $agent_id = $agent_lookup[$agent] ?? 0;
        if ($agent_id === -1)    $errors[] = 'Agent "' . $c['agent'] . '" matches more than one agent. Use the username instead.';
        elseif ($agent_id === 0) $errors[] = 'Agent "' . $c['agent'] . '" is not an active insurance agent. Use the full name or username from Manage Users, or leave it blank for the agent chosen on the upload page.';
        if ($agent_id <= 0) $agent_id = 0;
    }
    return ['d' => $d, 'agent_id' => $agent_id, 'errors' => $errors];
}

/** UPPER name => ['ABC 1234, XYZ 789', …] for active clients already in the system with one of these names. */
function imp_existing_names(mysqli $conn, array $names): array {
    $found = [];
    foreach (array_chunk($names, 200) as $chunk) {
        $st = $conn->prepare("
            SELECT c.full_name, GROUP_CONCAT(v.plate_number ORDER BY v.vehicle_id SEPARATOR ', ') AS plates
            FROM clients c LEFT JOIN vehicles v ON v.client_id = c.client_id
            WHERE c.deleted_at IS NULL AND c.full_name IN (" . implode(',', array_fill(0, count($chunk), '?')) . ")
            GROUP BY c.client_id");
        $st->bind_param(str_repeat('s', count($chunk)), ...$chunk);
        $st->execute();
        foreach ($st->get_result() as $row) $found[mb_strtoupper($row['full_name'])][] = $row['plates'] ?: 'no vehicle';
    }
    return $found;
}

/** Parsed rows -> preview rows: each with errors (not imported), warnings (imported unless unticked) and notes. */
function imp_build_preview(mysqli $conn, array $parsed, array $agent_lookup, int $default_agent): array {
    $rows = [];
    foreach ($parsed as $p) {
        $r = imp_check_row($p['cells'], $agent_lookup, $default_agent);
        $rows[] = ['line' => $p['line'], 'd' => $r['d'], 'agent_id' => $r['agent_id'], 'errors' => $r['errors'], 'warnings' => [], 'notes' => [], 'group' => null];
    }

    // Plates already in the system (spaces and hyphens ignored, so "ABC1234" is caught against "ABC 1234"), or twice in the file
    $on_file = [];
    $res = $conn->query("SELECT v.plate_number, c.full_name FROM vehicles v JOIN clients c ON c.client_id = v.client_id WHERE c.deleted_at IS NULL");
    while ($v = $res->fetch_assoc()) $on_file[imp_plate_key($v['plate_number'])] ??= $v;
    $seen = [];
    foreach ($rows as $i => &$r) {
        $plate = $r['d']['plate_number'];
        if ($plate === '' || !validate_plate($plate) || mb_strlen($plate) > MAX_PLATE) continue;
        $k = imp_plate_key($plate);
        if (isset($on_file[$k])) {
            $v = $on_file[$k];
            $r['errors'][] = (strtoupper($v['plate_number']) === $plate ? 'Plate ' . $plate . ' is already in the system' : 'Plate ' . $plate . ' matches ' . $v['plate_number'] . ', already in the system')
                           . ' (client ' . $v['full_name'] . ').';
        } elseif (isset($seen[$k])) {
            $r['errors'][] = 'Same plate as row ' . $rows[$seen[$k]]['line'] . ' of this file.';
        } else {
            $seen[$k] = $i;
        }
    }
    unset($r);

    // One client with several vehicles: same name + same address, or same name + same contact number
    $by_addr = $by_phone = $by_name = [];
    foreach ($rows as $i => &$r) {
        if ($r['errors']) continue;
        $name   = $r['d']['full_name'];
        $ka     = $name . '|' . mb_strtoupper($r['d']['address']);
        $kp     = $r['d']['contact_number'] !== '' ? $name . '|' . $r['d']['contact_number'] : null;
        $leader = $by_addr[$ka] ?? ($kp !== null ? ($by_phone[$kp] ?? null) : null);
        if ($leader !== null && $rows[$leader]['agent_id'] !== $r['agent_id']) {
            $r['errors'][] = 'Same client as row ' . $rows[$leader]['line'] . ' but a different agent. A client has only one agent.';
            continue;
        }
        if ($leader !== null) {
            $r['group']   = $leader;
            $r['notes'][] = 'Same client as row ' . $rows[$leader]['line'] . '. This vehicle is added to that client.';
        } else {
            $r['group'] = $i;
            if (isset($by_name[$name])) {
                $r['warnings'][] = 'Same name as row ' . $rows[$by_name[$name]]['line'] . ' but a different address and contact number. It will be saved as a separate client.';
            }
            $by_name[$name] ??= $i;
        }
        $by_addr[$ka] ??= $r['group'];
        if ($kp !== null) $by_phone[$kp] ??= $r['group'];
    }
    unset($r);

    // Same name as a client already in the system: a warning only (two people can share a name)
    $names = [];
    foreach ($rows as $i => $r) if (!$r['errors'] && $r['group'] === $i) $names[$r['d']['full_name']] = true;
    $existing = $names ? imp_existing_names($conn, array_keys($names)) : [];
    foreach ($rows as $i => &$r) {
        if ($r['errors'] || $r['group'] !== $i) continue;
        $match = $existing[$r['d']['full_name']] ?? null;
        if ($match) {
            $r['warnings'][] = 'A client with this name is already in the system (' . implode('; ', array_slice($match, 0, 3)) . ').'
                             . ' It will be saved as a new client. Untick Import if this is the same person.';
        }
    }
    unset($r);

    foreach ($rows as &$r) $r['status'] = $r['errors'] ? 'error' : ($r['warnings'] ? 'warning' : 'ready');
    unset($r);
    return $rows;
}

/** Saves the good rows. $ticked = first rows of clients with warnings that the user kept ticked. */
function imp_run(mysqli $conn, array $rows, array $ticked, array $agents): array {
    @set_time_limit(600);
    $uid  = (int)$_SESSION['user_id'];
    $out  = ['clients' => 0, 'vehicles' => 0, 'skipped' => [], 'agents' => []];
    $skip = function (array $idxs, string $why) use (&$out, $rows) {
        foreach ($idxs as $i) $out['skipped'][] = ['line' => $rows[$i]['line'], 'name' => $rows[$i]['d']['full_name'], 'plate' => $rows[$i]['d']['plate_number'], 'why' => $why];
    };
    $groups = [];
    foreach ($rows as $i => $r) if ($r['status'] !== 'error') $groups[$r['group']][] = $i;

    $ins_client  = $conn->prepare("INSERT INTO clients (full_name, contact_number, email, facebook_name, address, created_by, agent_id, consent_signed_at, consent_recorded_by, public_token) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?)");
    $ins_vehicle = $conn->prepare("INSERT INTO vehicles (client_id, plate_number, make, model, year_model, color, motor_number, serial_number) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    // One client with its vehicles (inside the caller's transaction). Client details come from its first row,
    // with blanks filled in from its other rows.
    $save = function (array $idxs) use ($conn, $rows, $uid, $ins_client, $ins_vehicle) {
        $c = $rows[$idxs[0]]['d'];
        foreach (['contact_number', 'email', 'facebook_name'] as $f) {
            foreach ($idxs as $i) if ($c[$f] === '' && $rows[$i]['d'][$f] !== '') $c[$f] = $rows[$i]['d'][$f];
        }
        $agent_id = $rows[$idxs[0]]['agent_id'];
        $token    = bin2hex(random_bytes(32));
        $ins_client->bind_param('sssssiiis', $c['full_name'], $c['contact_number'], $c['email'], $c['facebook_name'], $c['address'], $uid, $agent_id, $uid, $token);
        $ins_client->execute();
        $client_id = (int)$conn->insert_id;
        foreach ($idxs as $i) {
            $v = $rows[$i]['d'];
            $ins_vehicle->bind_param('isssisss', $client_id, $v['plate_number'], $v['make'], $v['model'], $v['year_model'], $v['color'], $v['motor_number'], $v['serial_number']);
            $ins_vehicle->execute();
        }
    };
    $saved = function (array $idxs) use (&$out, $rows) {
        $aid = $rows[$idxs[0]]['agent_id'];
        $out['clients']++;
        $out['vehicles'] += count($idxs);
        $out['agents'][$aid] = ($out['agents'][$aid] ?? 0) + 1;
    };

    // 25 clients at a time: their plates are locked together (the same per-plate locks Add Client uses, so a plate
    // typed in by hand at this moment cannot end up twice), checked in one query and saved in one transaction —
    // a few queries per batch instead of several per client, which matters on the slower live database.
    foreach (array_chunk($groups, 25, true) as $chunk) {
        $todo = [];
        foreach ($chunk as $leader => $idxs) {
            if ($rows[$leader]['warnings'] && !isset($ticked[$leader])) $skip($idxs, 'Unticked on the preview.');
            elseif (!isset($agents[$rows[$leader]['agent_id']]))        $skip($idxs, 'The insurance agent is no longer active.');
            else                                                         $todo[$leader] = $idxs;
        }
        if (!$todo) continue;

        $lock_of = [];
        foreach ($todo as $idxs) foreach ($idxs as $i) $lock_of[$i] = named_lock_name('plate_' . $rows[$i]['d']['plate_number']);
        $st = $conn->prepare('SELECT ' . implode(', ', array_fill(0, count($lock_of), 'GET_LOCK(?, 5)')));
        $st->bind_param(str_repeat('s', count($lock_of)), ...array_values($lock_of));
        $st->execute();
        $got  = $st->get_result()->fetch_row();
        $held = [];
        foreach (array_keys($lock_of) as $n => $i) if ((int)$got[$n] === 1) $held[$i] = $lock_of[$i];

        try {
            $plates = [];
            foreach ($todo as $leader => $idxs) {
                if (count(array_diff_key(array_flip($idxs), $held)) > 0) {
                    $skip($idxs, 'The system was busy with this plate. Upload the file again to retry.');
                    unset($todo[$leader]);
                    continue;
                }
                foreach ($idxs as $i) $plates[] = $rows[$i]['d']['plate_number'];
            }
            if (!$todo) continue;

            // Plates someone saved by hand since the preview
            $st = $conn->prepare("SELECT v.plate_number FROM vehicles v INNER JOIN clients c ON v.client_id = c.client_id
                                  WHERE c.deleted_at IS NULL AND v.plate_number IN (" . implode(',', array_fill(0, count($plates), '?')) . ")");
            $st->bind_param(str_repeat('s', count($plates)), ...$plates);
            $st->execute();
            $taken = [];
            foreach ($st->get_result() as $v) $taken[strtoupper($v['plate_number'])] = true;
            foreach ($todo as $leader => $idxs) {
                $keep = [];
                foreach ($idxs as $i) {
                    $plate = $rows[$i]['d']['plate_number'];
                    if (isset($taken[$plate])) $skip([$i], 'Plate ' . $plate . ' was added to the system after the preview.');
                    else                       $keep[] = $i;
                }
                if ($keep) $todo[$leader] = $keep;
                else       unset($todo[$leader]);
            }
            if (!$todo) continue;

            try {
                $conn->begin_transaction();
                foreach ($todo as $idxs) $save($idxs);
                $conn->commit();
                foreach ($todo as $idxs) $saved($idxs);
            } catch (Throwable $e) {
                // Something in the batch failed: save its clients one by one, so one bad row cannot sink the other 24
                $conn->rollback();
                foreach ($todo as $leader => $idxs) {
                    try {
                        $conn->begin_transaction();
                        $save($idxs);
                        $conn->commit();
                        $saved($idxs);
                    } catch (Throwable $e) {
                        $conn->rollback();
                        error_log('[TG-BASICS] client import, row ' . $rows[$leader]['line'] . ': ' . $e->getMessage());
                        $skip($idxs, 'Could not be saved (database error).');
                    }
                }
            }
        } finally {
            if ($held) {
                $st = $conn->prepare('SELECT ' . implode(', ', array_fill(0, count($held), 'RELEASE_LOCK(?)')));
                $st->bind_param(str_repeat('s', count($held)), ...array_values($held));
                $st->execute();
                $st->get_result();
            }
        }
    }
    usort($out['skipped'], fn($a, $b) => $a['line'] <=> $b['line']);
    return $out;
}

// ── REQUEST ───────────────────────────────────────────────────────────────

$agents           = insurance_agents($conn);
$default_agent_id = isset($agents[(int)$_SESSION['user_id']]) ? (int)$_SESSION['user_id'] : 0;

// Blank template with the column names (BOM so Excel opens it as UTF-8)
if (isset($_GET['template'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="client_import_template.csv"');
    echo "\xEF\xBB\xBF" . implode(',', array_column(IMPORT_COLUMNS, 0)) . "\r\n";   // no column name needs quoting
    exit;
}

$preview = $_SESSION['client_import'] ?? null;
if ($preview && (time() - $preview['created'] > IMPORT_TTL || $preview['user_id'] !== (int)$_SESSION['user_id'])) {
    unset($_SESSION['client_import']);
    $preview = null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'cancel') {
        unset($_SESSION['client_import']);
        header('Location: import_clients.php');
        exit;
    }

    if ($action === 'upload') {
        $agent_id = san_int($_POST['agent_id'] ?? 0, 1);
        $fail = function (string $msg) use ($agent_id) {
            $_SESSION['client_import_error'] = ['msg' => $msg, 'agent_id' => $agent_id];
            header('Location: import_clients.php');
            exit;
        };
        unset($_SESSION['client_import'], $_SESSION['client_import_result']);
        if (!isset($agents[$agent_id]))          $fail('Please select the insurance agent.');
        if (($_POST['consent'] ?? '') !== '1')    $fail('Please confirm that every client in the file has signed the printed Data Privacy Consent Form.');
        $f = $_FILES['csv_file'] ?? null;
        if (!is_array($f) || !is_int($f['error'] ?? null) || $f['error'] === UPLOAD_ERR_NO_FILE) $fail('Please choose the CSV file to import.');
        if (in_array($f['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || ($f['error'] === UPLOAD_ERR_OK && $f['size'] > IMPORT_MAX_BYTES)) $fail('The file is too large (max 2 MB).');
        if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) $fail('The file could not be uploaded. Please try again.');
        $ext = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['xlsx', 'xls', 'xlsm'], true)) $fail('That is an Excel workbook, not a CSV file. In Excel choose File > Save As > "CSV UTF-8 (Comma delimited)", then upload the new file.');
        if (!in_array($ext, ['csv', 'txt'], true))  $fail('Please upload a .csv file.');

        $parsed = imp_read_csv((string)file_get_contents($f['tmp_name']));
        if (isset($parsed['error'])) $fail($parsed['error']);
        $file_label = mb_substr(preg_replace('/[^\pL\pN ._()\-]/u', '', basename((string)$f['name'])) ?? '', 0, 80);
        $_SESSION['client_import'] = [
            'user_id'  => (int)$_SESSION['user_id'],
            'created'  => time(),
            'token'    => bin2hex(random_bytes(16)),
            'agent_id' => $agent_id,
            'file'     => $file_label !== '' ? $file_label : 'file.csv',
            'rows'     => imp_build_preview($conn, $parsed['rows'], imp_agent_lookup($conn, $agents), $agent_id),
        ];
        header('Location: import_clients.php');
        exit;
    }

    if ($action === 'import') {
        $token = is_string($_POST['token'] ?? null) ? $_POST['token'] : '';
        // A second click that waited behind the first import lands on that import's result instead of an error
        if (!$preview && $token !== '' && hash_equals($_SESSION['client_import_result']['token'] ?? '', $token)) {
            header('Location: import_clients.php?done=1');
            exit;
        }
        if (!$preview || !hash_equals($preview['token'], $token)) {
            $_SESSION['client_import_error'] = ['msg' => 'This preview has expired or was already imported. Please upload the file again.', 'agent_id' => 0];
            header('Location: import_clients.php');
            exit;
        }
        unset($_SESSION['client_import']);   // once only — a refresh or a second click cannot import the same rows twice
        $ticked = [];
        foreach ((array)($_POST['include'] ?? []) as $i) if (is_scalar($i)) $ticked[(int)$i] = true;
        $out = imp_run($conn, $preview['rows'], $ticked, $agents);

        if ($out['clients'] > 0) {
            $uid   = (int)$_SESSION['user_id'];
            $names = array_map(fn($aid) => $agents[$aid]['full_name'], array_keys($out['agents']));
            $desc  = ($_SESSION['full_name'] ?? 'Unknown') . ' imported ' . $out['clients'] . ' client(s) with ' . $out['vehicles'] . ' vehicle(s) from "' . $preview['file'] . '"'
                   . ' for insurance agent' . (count($names) > 1 ? 's ' : ' ') . implode(', ', $names) . '.';
            $log = $conn->prepare("INSERT INTO audit_logs (user_id, action, description) VALUES (?, 'CLIENT_IMPORTED', ?)");
            $log->bind_param('is', $uid, $desc);
            $log->execute();
        }
        $_SESSION['client_import_result'] = $out + [
            'token'  => $preview['token'],
            'file'   => $preview['file'],
            'errors' => count(array_filter($preview['rows'], fn($r) => $r['status'] === 'error')),
        ];
        header('Location: import_clients.php?done=1');
        exit;
    }

    header('Location: import_clients.php');
    exit;
}

$flash  = $_SESSION['client_import_error'] ?? null;
unset($_SESSION['client_import_error']);
$result = isset($_GET['done']) ? ($_SESSION['client_import_result'] ?? null) : null;
$step   = $result ? 'done' : ($preview ? 'preview' : 'upload');

$page_title  = 'Import Clients';
$active_page = 'clients';
$base_path   = '../../';
require_once '../../includes/header.php';
require_once '../../includes/navbar.php';
?>

<div class="main">
<?php
$topbar_title      = 'Import Clients';
$topbar_breadcrumb = ['Records', 'Client Records', 'Import'];
require_once '../../includes/topbar.php';
?>

  <div class="content">

    <a href="client_list.php" class="back-link"><?= icon('arrow-left', 14) ?> Back to Client Records</a>

    <style>
      .imp-steps { display:flex; gap:0.5rem; flex-wrap:wrap; margin-bottom:1.25rem; }
      .imp-step { display:flex; align-items:center; gap:0.45rem; font-size:0.74rem; font-weight:600; color:var(--text-muted); padding:0.35rem 0.8rem; border:1px solid var(--border); border-radius:100px; background:var(--bg-2); }
      .imp-step b { display:inline-flex; align-items:center; justify-content:center; width:18px; height:18px; border-radius:50%; background:var(--border); color:var(--text-secondary); font-size:0.65rem; }
      .imp-step.is-on { color:var(--text-primary); border-color:var(--gold-bright); background:var(--gold-pale); }
      .imp-step.is-on b { background:var(--gold-bright); color:#fff; }
      .imp-step.is-done b { background:var(--success); color:#fff; }
      .imp-drop { display:flex; flex-direction:column; align-items:center; justify-content:center; gap:0.35rem; border:2px dashed var(--border); border-radius:12px; padding:1.6rem 1rem; text-align:center; cursor:pointer; transition:border-color 0.15s, background 0.15s; }
      .imp-drop:hover, .imp-drop.is-over { border-color:var(--gold-bright); background:var(--gold-pale); }
      .imp-drop.has-file { border-style:solid; border-color:var(--success); }
      .imp-drop input { position:absolute; width:1px; height:1px; opacity:0; pointer-events:none; }
      .imp-help ol { margin:0; padding-left:1.1rem; font-size:0.8rem; color:var(--text-secondary); line-height:1.65; }
      .imp-help li + li { margin-top:0.35rem; }
      .imp-cols { width:100%; border-collapse:collapse; font-size:0.74rem; margin-top:0.6rem; }
      .imp-cols td { padding:0.35rem 0.5rem; border-bottom:1px solid var(--border); color:var(--text-secondary); vertical-align:top; }
      .imp-cols td:first-child { font-weight:700; color:var(--text-primary); white-space:nowrap; }
      .imp-stats { display:grid; grid-template-columns:repeat(4, minmax(0, 1fr)); gap:0.75rem; margin-bottom:1.25rem; }
      .imp-stat { background:var(--bg-3); border:1px solid var(--border); border-radius:12px; padding:0.85rem 1rem; }
      .imp-stat-num { font-size:1.35rem; font-weight:800; color:var(--text-primary); line-height:1.1; }
      .imp-stat-label { font-size:0.7rem; color:var(--text-muted); font-weight:600; margin-top:0.2rem; }
      .imp-stat.is-ok .imp-stat-num { color:var(--success); }
      .imp-stat.is-warn .imp-stat-num { color:var(--warning); }
      .imp-stat.is-err .imp-stat-num { color:var(--danger); }
      .imp-tabs { display:flex; gap:0.35rem; flex-wrap:wrap; }
      .imp-tab { font:inherit; font-size:0.74rem; font-weight:600; padding:0.35rem 0.8rem; border-radius:100px; border:1px solid var(--border); background:var(--bg-2); color:var(--text-secondary); cursor:pointer; }
      .imp-tab.is-on { background:var(--gold-bright); border-color:var(--gold-bright); color:#fff; }
      .imp-table .plate-chip { white-space:nowrap; }
      .imp-table td.imp-notes { font-size:0.72rem; line-height:1.45; max-width:340px; }
      .imp-note-error { color:var(--danger); }
      .imp-note-warning { color:var(--warning); }
      .imp-note-info { color:var(--text-muted); }
      .imp-table tr.is-skipped td { opacity:0.55; }
      .imp-bar { display:flex; align-items:center; gap:0.75rem; flex-wrap:wrap; padding:1rem 1.25rem; border-top:1px solid var(--border); }
      @media (max-width: 768px) { .imp-stats { grid-template-columns:repeat(2, minmax(0, 1fr)); } .imp-bar > * { width:100%; justify-content:center; } .imp-table { min-width:900px; } }
    </style>

    <div class="imp-steps" aria-label="Import steps">
      <span class="imp-step <?= $step === 'upload' ? 'is-on' : 'is-done' ?>"><b>1</b> Upload file</span>
      <span class="imp-step <?= $step === 'preview' ? 'is-on' : ($step === 'done' ? 'is-done' : '') ?>"><b>2</b> Check preview</span>
      <span class="imp-step <?= $step === 'done' ? 'is-on' : '' ?>"><b>3</b> Import</span>
    </div>

<?php if ($step === 'upload'): ?>

    <?php if ($flash): ?>
    <div class="alert alert-danger" role="alert"><?= icon('exclamation-triangle', 15) ?> <span><?= htmlspecialchars($flash['msg']) ?></span></div>
    <?php endif; ?>

    <div style="display:grid;grid-template-columns:minmax(0, 1fr) minmax(0, 1fr);gap:1.25rem;align-items:start;">

      <div class="card" style="margin-bottom:0;">
        <div class="card-header">
          <div class="card-icon"><?= icon('arrow-up-tray', 16) ?></div>
          <div>
            <div class="card-title">Import Clients from a File</div>
            <div class="card-sub">Up to <?= number_format(IMPORT_MAX_ROWS) ?> rows per file. Nothing is saved until you check the preview.</div>
          </div>
        </div>
        <?php /* header stays outside the form: the phone rule ".content form > div" would stack it */ ?>
        <form method="POST" action="" enctype="multipart/form-data" id="imp-upload-form" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="upload"/>
        <div style="padding:1.5rem;">
          <div class="field" style="margin-bottom:1rem;">
            <label class="field-label" for="imp-agent">Insurance Agent <span class="req">*</span></label>
            <?php $sel_agent = ($flash['agent_id'] ?? 0) ?: $default_agent_id; ?>
            <select name="agent_id" id="imp-agent" class="field-select">
              <option value="" disabled <?= isset($agents[$sel_agent]) ? '' : 'selected' ?>>— Select insurance agent —</option>
              <?php foreach ($agents as $aid => $ag): ?>
              <option value="<?= $aid ?>" <?= $sel_agent === $aid ? 'selected' : '' ?>><?= htmlspecialchars(agent_option_label($ag)) ?></option>
              <?php endforeach; ?>
            </select>
            <div class="field-hint">Every client in the file goes to this agent, unless a row has its own name in the Agent column.</div>
          </div>

          <div class="field" style="margin-bottom:1rem;">
            <span class="field-label">CSV File <span class="req">*</span></span>
            <label class="imp-drop" id="imp-drop" style="position:relative;">
              <input type="file" name="csv_file" id="imp-file" accept=".csv,text/csv"/>
              <span style="color:var(--gold-bright);"><?= icon('document-text', 26) ?></span>
              <span id="imp-file-name" style="font-size:0.85rem;font-weight:700;color:var(--text-primary);word-break:break-all;">Choose a CSV file or drop it here</span>
              <span style="font-size:0.72rem;color:var(--text-muted);">.csv only, max 2 MB</span>
            </label>
          </div>

          <div class="field">
            <label style="display:flex;align-items:flex-start;gap:0.6rem;font-size:0.8rem;color:var(--text-secondary);line-height:1.5;cursor:pointer;">
              <input type="checkbox" name="consent" value="1" id="imp-consent" style="margin-top:0.2rem;width:16px;height:16px;flex-shrink:0;accent-color:var(--gold-bright);"/>
              <span>I confirm that <strong>every client in this file</strong> has signed the printed Data Privacy Consent Form. I am recorded as the one who confirmed it.</span>
            </label>
          </div>
        </div>
        <div class="form-actions">
          <a href="client_list.php" class="btn-ghost">Cancel</a>
          <button type="submit" class="btn-primary" id="imp-upload-btn"><?= icon('magnifying-glass', 14) ?> Check File</button>
        </div>
        </form>
      </div>

      <div class="card imp-help" style="margin-bottom:0;">
        <div class="card-header">
          <div class="card-icon"><?= icon('information-circle', 16) ?></div>
          <div>
            <div class="card-title">How to Prepare the File</div>
            <div class="card-sub">Works with Excel or Google Sheets</div>
          </div>
          <a href="?template=1" class="btn-ghost" style="margin-left:auto;font-size:0.78rem;padding:0.45rem 1rem;"><?= icon('arrow-down-tray', 13) ?> Download Template</a>
        </div>
        <div style="padding:1.25rem 1.5rem;">
          <ol>
            <li>Download the template and open it in Excel.</li>
            <li>Type <strong>one row per vehicle</strong>. For a client with two vehicles, use two rows with the same name and address; they are joined into one client.</li>
            <li>Save it as CSV: in Excel, <strong>File &gt; Save As &gt; CSV UTF-8 (Comma delimited)</strong>. In Google Sheets, <strong>File &gt; Download &gt; CSV</strong>.</li>
            <li>Upload it here and check the preview. Rows with errors are never saved; fix them in the file and upload it again.</li>
          </ol>
          <table class="imp-cols">
            <tr><td>Full Name *</td><td>Letters, spaces, dots, hyphens and apostrophes. Saved in capital letters.</td></tr>
            <tr><td>Address *</td><td>Complete address.</td></tr>
            <tr><td>Plate Number *</td><td>Must not already be in the system or twice in the file.</td></tr>
            <tr><td>Make, Model *</td><td>For example Toyota, Vios.</td></tr>
            <tr><td>Year Model *</td><td>1960 to <?= (int)date('Y') + 1 ?>.</td></tr>
            <tr><td>Contact Number</td><td>Optional. 09XXXXXXXXX; a missing leading 0 or +63 is fixed automatically.</td></tr>
            <tr><td>Email, Facebook Name</td><td>Optional.</td></tr>
            <tr><td>Color, Engine No., Chassis No.</td><td>Optional.</td></tr>
            <tr><td>Agent</td><td>Optional. The agent's full name or username. Blank means the agent chosen in the upload form.</td></tr>
          </table>
        </div>
      </div>
    </div>

<?php elseif ($step === 'preview'):
    $rows     = $preview['rows'];
    $count    = array_count_values(array_column($rows, 'status')) + ['ready' => 0, 'warning' => 0, 'error' => 0];
    $agent_nm = fn(int $aid) => isset($agents[$aid]) ? $agents[$aid]['full_name'] : '—';
?>

    <div class="imp-stats">
      <div class="imp-stat"><div class="imp-stat-num"><?= number_format(count($rows)) ?></div><div class="imp-stat-label">Rows in <?= htmlspecialchars($preview['file']) ?></div></div>
      <div class="imp-stat is-ok"><div class="imp-stat-num"><?= number_format($count['ready']) ?></div><div class="imp-stat-label">Ready</div></div>
      <div class="imp-stat is-warn"><div class="imp-stat-num"><?= number_format($count['warning']) ?></div><div class="imp-stat-label">Warnings (please check)</div></div>
      <div class="imp-stat is-err"><div class="imp-stat-num"><?= number_format($count['error']) ?></div><div class="imp-stat-label">Errors (will be skipped)</div></div>
    </div>

    <?php if ($count['error'] > 0): ?>
    <div class="alert alert-danger" role="alert"><?= icon('exclamation-triangle', 15) ?>
      <span><?= number_format($count['error']) ?> row<?= $count['error'] > 1 ? 's have' : ' has' ?> errors and will not be saved. You can import the other rows now, then fix those rows in the file and upload it again (rows already saved will show up as duplicates and be skipped).</span>
    </div>
    <?php endif; ?>
    <?php if ($count['warning'] > 0): ?>
    <div class="alert alert-warning" role="status"><?= icon('information-circle', 15) ?>
      <span>Rows with warnings will be saved unless you untick them in the Import column. Check each one first.</span>
    </div>
    <?php endif; ?>

    <div class="card" style="margin-bottom:0;">
      <div class="card-header">
        <div class="card-icon"><?= icon('document-text', 16) ?></div>
        <div>
          <div class="card-title">Preview</div>
          <div class="card-sub">Default agent: <?= htmlspecialchars($agent_nm($preview['agent_id'])) ?></div>
        </div>
        <div class="imp-tabs" role="group" aria-label="Show rows" style="margin-left:auto;">
          <button type="button" class="imp-tab is-on" data-show="all" aria-pressed="true">All (<?= number_format(count($rows)) ?>)</button>
          <button type="button" class="imp-tab" data-show="ready" aria-pressed="false">Ready (<?= number_format($count['ready']) ?>)</button>
          <button type="button" class="imp-tab" data-show="warning" aria-pressed="false">Warnings (<?= number_format($count['warning']) ?>)</button>
          <button type="button" class="imp-tab" data-show="error" aria-pressed="false">Errors (<?= number_format($count['error']) ?>)</button>
        </div>
      </div>
      <form method="POST" action="" id="imp-import-form">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="import"/>
      <input type="hidden" name="token" value="<?= htmlspecialchars($preview['token']) ?>"/>

      <div class="tg-table-wrap" style="max-height:65vh;overflow:auto;">
        <table class="tg-table imp-table">
          <thead>
            <tr>
              <th>Row</th>
              <th>Client</th>
              <th>Contact</th>
              <th>Plate</th>
              <th>Vehicle</th>
              <th>Agent</th>
              <th>Notes</th>
              <th>Status</th>
              <th>Import</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $i => $r):
              $d = $r['d'];
              $is_leader = $r['status'] !== 'error' && $r['group'] === $i;
            ?>
            <tr data-status="<?= $r['status'] ?>" <?= $r['status'] !== 'error' ? 'data-group="' . (int)$r['group'] . '"' : '' ?>>
              <td style="font-weight:700;color:var(--text-muted);"><?= (int)$r['line'] ?></td>
              <td>
                <div style="font-weight:700;color:var(--text-primary);"><?= htmlspecialchars($d['full_name'] !== '' ? $d['full_name'] : '—') ?></div>
                <div style="font-size:0.7rem;color:var(--text-muted);"><?= htmlspecialchars($d['address']) ?></div>
              </td>
              <td><?= htmlspecialchars($d['contact_number'] !== '' ? $d['contact_number'] : '—') ?></td>
              <td><?= $d['plate_number'] !== '' ? '<span class="plate-chip">' . htmlspecialchars($d['plate_number']) . '</span>' : '—' ?></td>
              <td>
                <?= htmlspecialchars(trim(($d['year_model'] ?: '') . ' ' . $d['make'] . ' ' . $d['model']) ?: '—') ?>
                <?php if ($d['color'] !== ''): ?><div style="font-size:0.7rem;color:var(--text-muted);"><?= htmlspecialchars($d['color']) ?></div><?php endif; ?>
              </td>
              <td><?= htmlspecialchars($r['agent_id'] ? $agent_nm($r['agent_id']) : '—') ?></td>
              <td class="imp-notes">
                <?php foreach ($r['errors'] as $m): ?><div class="imp-note-error"><?= htmlspecialchars($m) ?></div><?php endforeach; ?>
                <?php foreach ($r['warnings'] as $m): ?><div class="imp-note-warning"><?= htmlspecialchars($m) ?></div><?php endforeach; ?>
                <?php foreach ($r['notes'] as $m): ?><div class="imp-note-info"><?= htmlspecialchars($m) ?></div><?php endforeach; ?>
                <?php if (!$r['errors'] && !$r['warnings'] && !$r['notes']): ?><span style="color:var(--text-muted);">—</span><?php endif; ?>
              </td>
              <td>
                <?php if ($r['status'] === 'error'): ?><span class="badge badge-red">Error</span>
                <?php elseif ($r['status'] === 'warning'): ?><span class="badge badge-yellow">Warning</span>
                <?php else: ?><span class="badge badge-green">Ready</span><?php endif; ?>
              </td>
              <td>
                <?php if ($r['status'] === 'error'): ?>
                  <span style="color:var(--text-muted);">—</span>
                <?php elseif ($is_leader && $r['status'] === 'warning'): ?>
                  <input type="checkbox" name="include[]" value="<?= $i ?>" class="imp-include" checked aria-label="Import row <?= (int)$r['line'] ?>" style="width:16px;height:16px;accent-color:var(--gold-bright);cursor:pointer;"/>
                <?php elseif ($is_leader): ?>
                  <span style="color:var(--success);" title="Will be imported"><?= icon('check', 15) ?></span>
                <?php else: ?>
                  <span style="font-size:0.7rem;color:var(--text-muted);">with row <?= (int)$rows[$r['group']]['line'] ?></span>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
            <tr class="imp-empty" hidden><td colspan="9" style="padding:2rem;color:var(--text-muted);">No rows here.</td></tr>
          </tbody>
        </table>
      </div>

      <div class="imp-bar">
        <button type="button" class="btn-ghost" id="imp-cancel"><?= icon('x-mark', 14) ?> Start Over</button>
        <?php if ($count['warning'] > 0): ?>
        <button type="button" class="btn-ghost" id="imp-untick"><?= icon('x-circle', 14) ?> Untick All Warnings</button>
        <?php endif; ?>
        <span id="imp-summary" style="margin-left:auto;font-size:0.8rem;color:var(--text-secondary);"></span>
        <button type="submit" class="btn-primary" id="imp-go"><?= icon('user-plus', 14) ?> <span>Import</span></button>
      </div>
      </form>
    </div>
    <form method="POST" action="" id="imp-cancel-form" hidden><?= csrf_field() ?><input type="hidden" name="action" value="cancel"/></form>

<?php else:
    $single = count($result['agents']) === 1 ? (int)array_key_first($result['agents']) : 0;
    $list_q = count($result['agents']) > 1 ? '?agent=all' : ($single && $single !== (int)$_SESSION['user_id'] ? '?agent=' . $single : '');
?>

    <div class="card" style="margin-bottom:1.25rem;">
      <div class="card-header">
        <div class="card-icon"><?= icon('check-circle', 16) ?></div>
        <div>
          <div class="card-title">Import Finished</div>
          <div class="card-sub"><?= htmlspecialchars($result['file']) ?></div>
        </div>
      </div>
      <div style="padding:1.25rem 1.5rem;">
        <div class="imp-stats" style="margin-bottom:0;">
          <div class="imp-stat is-ok"><div class="imp-stat-num"><?= number_format($result['clients']) ?></div><div class="imp-stat-label">Clients added</div></div>
          <div class="imp-stat is-ok"><div class="imp-stat-num"><?= number_format($result['vehicles']) ?></div><div class="imp-stat-label">Vehicles added</div></div>
          <div class="imp-stat is-warn"><div class="imp-stat-num"><?= number_format(count($result['skipped'])) ?></div><div class="imp-stat-label">Rows skipped</div></div>
          <div class="imp-stat is-err"><div class="imp-stat-num"><?= number_format($result['errors']) ?></div><div class="imp-stat-label">Rows with errors (not saved)</div></div>
        </div>
      </div>
      <div class="form-actions">
        <a href="import_clients.php" class="btn-ghost"><?= icon('arrow-up-tray', 14) ?> Import Another File</a>
        <a href="client_list.php<?= $list_q ?>" class="btn-primary"><?= icon('users', 14) ?> View Client Records</a>
      </div>
    </div>

    <?php if ($result['skipped']): ?>
    <div class="card" style="margin-bottom:0;">
      <div class="card-header">
        <div class="card-icon"><?= icon('exclamation-triangle', 16) ?></div>
        <div>
          <div class="card-title">Skipped Rows</div>
          <div class="card-sub">These rows were not saved</div>
        </div>
      </div>
      <div class="tg-table-wrap">
        <table class="tg-table">
          <thead><tr><th>Row</th><th>Client</th><th>Plate</th><th>Reason</th></tr></thead>
          <tbody>
            <?php foreach ($result['skipped'] as $s): ?>
            <tr>
              <td style="font-weight:700;color:var(--text-muted);"><?= (int)$s['line'] ?></td>
              <td style="font-weight:700;color:var(--text-primary);"><?= htmlspecialchars($s['name']) ?></td>
              <td><span class="plate-chip"><?= htmlspecialchars($s['plate']) ?></span></td>
              <td style="font-size:0.75rem;"><?= htmlspecialchars($s['why']) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>

<?php endif; ?>

  </div>
</div>

<?php
$footer_extra_scripts = '<script src="../../assets/js/shared/import_clients.js?v=' . filemtime(__DIR__ . '/../../assets/js/shared/import_clients.js') . '"></script>';
require_once '../../includes/footer.php';
?>
