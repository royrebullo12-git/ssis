<?php
declare(strict_types=1);
if (!defined('SSIS_BOOT')) { http_response_code(403); exit('Direct access forbidden.'); }

/**
 * Shared clearance approval screen for Registrar, Cashier and Department.
 * $type = registrar | cashier | department. Department users only see/modify
 * rows for their own department_id (server-side scope check, not a UI filter).
 */
function clearance_review_page(array $user, string $type, string $title): void
{
    $pdo = db();
    $isDept = $type === 'department';
    if ($isDept && empty($user['department_id'])) {
        http_response_code(403);
        exit('Your account is not linked to a department. Contact the Admin office.');
    }
    $scopeSql    = $isDept ? 'c.department_id = ?' : 'c.department_id IS NULL';
    $scopeParams = $isDept ? [(int)$user['department_id']] : [];

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        post_guard();
        $id      = post_int('id');
        $action  = post_str('action', 10);
        $remarks = post_str('remarks', 255);

        $st = $pdo->prepare(
            "SELECT c.*, s.student_no FROM clearances c JOIN students s ON s.id = c.student_id
              WHERE c.id = ? AND c.clearance_type = ? AND {$scopeSql}"
        );
        $st->execute(array_merge([$id, $type], $scopeParams));
        $c = $st->fetch();

        if (!$c || !in_array($action, ['approve', 'reject'], true)) {
            audit_log('ACCESS_DENIED', (int)$user['id'], $user['username'], 'clearance', $id, 'Out-of-scope clearance action');
            flash('error', 'That clearance record is not available to you.');
        } elseif ($action === 'reject' && $remarks === '') {
            flash('error', 'Enter a reason when rejecting a clearance.');
        } elseif ($action === 'approve' && $type === 'cashier' && ($bal = student_balance((int)$c['student_id'])) > 0) {
            flash('error', 'Cannot clear ' . $c['student_no'] . ': outstanding balance ' . money($bal) . '.');
        } else {
            $new = $action === 'approve' ? 'approved' : 'rejected';
            $pdo->prepare('UPDATE clearances SET status=?, remarks=?, reviewed_by=?, reviewed_at=NOW() WHERE id=?')
                ->execute([$new, $remarks !== '' ? $remarks : null, (int)$user['id'], $id]);
            audit_log('CLEARANCE_' . strtoupper($new), (int)$user['id'], $user['username'], 'clearance', $id, $c['student_no'] . ' ' . $remarks);
            flash('success', 'Clearance ' . $new . ' for ' . $c['student_no'] . '.');
        }
        redirect_self();
    }

    $status = get_str('status', 10);
    if (!in_array($status, ['pending', 'approved', 'rejected', 'all'], true)) $status = 'pending';
    $q = get_str('q', 60);

    $registrarFilters = $type === 'registrar';
    if ($registrarFilters) {
        $years = array_values(array_unique(array_map('strval', $pdo->query(
            'SELECT DISTINCT school_year FROM clearances WHERE clearance_type = "registrar" ORDER BY school_year DESC'
        )->fetchAll(PDO::FETCH_COLUMN))));
        $departments = $pdo->query('SELECT id, code FROM departments ORDER BY code')->fetchAll();
        $readMulti = static function (string $key): array {
            $value = $_GET[$key] ?? [];
            if (is_string($value) && $value !== '') $value = [$value];
            if (!is_array($value)) return [];
            return array_values(array_unique(array_filter($value, static fn($item): bool => is_string($item))));
        };
        $selectedYears = array_values(array_intersect($readMulti('academic_year'), $years));
        $selectedSemesters = array_values(array_intersect($readMulti('semester'), ['1st', '2nd', 'summer']));
        $validDepartmentIds = array_map(static fn(array $department): string => (string)$department['id'], $departments);
        $selectedDepartments = array_values(array_intersect($readMulti('department'), $validDepartmentIds));
        $selectedStatuses = array_values(array_intersect($readMulti('status'), ['pending', 'approved', 'rejected']));
    }

    $sql = "SELECT c.id, c.status, c.remarks, c.reviewed_at, c.school_year, c.semester,
                   s.student_no, s.first_name, s.last_name, s.program, d.code AS department_code
              FROM clearances c JOIN students s ON s.id = c.student_id
              JOIN departments d ON d.id = s.department_id
             WHERE c.clearance_type = ? AND {$scopeSql}";
    $params = array_merge([$type], $scopeParams);
    if (!$registrarFilters) {
        $sql .= ' AND c.school_year = ? AND c.semester = ?';
        array_push($params, CURRENT_SY, CURRENT_SEM);
    } else {
        foreach ([
            ['c.school_year', $selectedYears],
            ['c.semester', $selectedSemesters],
            ['s.department_id', array_map('intval', $selectedDepartments)],
            ['c.status', $selectedStatuses],
        ] as [$column, $values]) {
            if ($values) {
                $sql .= ' AND ' . $column . ' IN (' . implode(',', array_fill(0, count($values), '?')) . ')';
                array_push($params, ...$values);
            }
        }
    }
    if (!$registrarFilters && $status !== 'all') { $sql .= ' AND c.status = ?'; $params[] = $status; }
    if ($q !== '') {
        $sql .= ' AND (s.student_no LIKE ? OR s.last_name LIKE ? OR s.first_name LIKE ?)';
        $like = '%' . addcslashes($q, '%_\\') . '%';
        array_push($params, $like, $like, $like);
    }
    $sql .= ' ORDER BY s.last_name, s.first_name LIMIT 200';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll();

    render_header($user, $title);
    echo '<p class="muted">' . ($registrarFilters ? 'Use the filters to view clearance records across academic terms.' : 'Term: ' . e(CURRENT_SY) . ', ' . e(CURRENT_SEM) . ' semester')
       . ($type === 'cashier' ? '. A clearance can only be approved when the student has no unpaid balance.' : '') . '</p>';
    echo '<form class="filters card" method="get"><div><label for="q">Search</label><input id="q" name="q" type="search" value="' . e($q) . '" placeholder="Student no. or name"></div>';
    if ($registrarFilters) {
        echo '<div><label for="academic_year">Academic year</label><select id="academic_year" name="academic_year"><option value="">All years</option>';
        foreach ($years as $year) echo '<option value="' . e($year) . '"' . (in_array($year, $selectedYears, true) ? ' selected' : '') . '>' . e($year) . '</option>';
        echo '</select></div><div><label for="semester">Term / semester</label><select id="semester" name="semester"><option value="">All terms</option>';
        foreach (['1st', '2nd', 'summer'] as $term) echo '<option value="' . e($term) . '"' . (in_array($term, $selectedSemesters, true) ? ' selected' : '') . '>' . e(label($term)) . '</option>';
        echo '</select></div><div><label for="department">Department</label><select id="department" name="department"><option value="">All departments</option>';
        foreach ($departments as $department) echo '<option value="' . (int)$department['id'] . '"' . (in_array((string)$department['id'], $selectedDepartments, true) ? ' selected' : '') . '>' . e($department['code']) . '</option>';
        echo '</select></div><div><label for="status">Status</label><select id="status" name="status"><option value="">All statuses</option>';
        foreach (['pending', 'approved', 'rejected'] as $clearanceStatus) echo '<option value="' . e($clearanceStatus) . '"' . (in_array($clearanceStatus, $selectedStatuses, true) ? ' selected' : '') . '>' . e(label($clearanceStatus)) . '</option>';
        echo '</select></div>';
    } else {
        echo '<div><label for="status">Status</label><select id="status" name="status">';
        foreach (['pending', 'approved', 'rejected', 'all'] as $s) {
            echo '<option value="' . $s . '"' . ($s === $status ? ' selected' : '') . '>' . e(label($s)) . '</option>';
        }
        echo '</select></div>';
    }
    echo '<button class="btn" type="submit">Apply filters</button><a class="btn alt" href="' . e(url($type === 'registrar' ? '/registrar/clearances.php' : ($type === 'cashier' ? '/cashier/clearances.php' : '/department/clearances.php'))) . '">Clear</a></form>';

    if (!$rows) {
        echo '<div class="card empty">No clearances match these filters.</div>';
    } else {
        echo '<div class="tablewrap table-responsive"><table><thead><tr><th>Student</th><th>Program</th>' . ($registrarFilters ? '<th>Department</th><th>Academic term</th>' : '') . '<th>Status</th><th>Reviewed</th><th>Decision</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            echo '<tr><td>' . e($r['student_no']) . '<br>' . e($r['last_name'] . ', ' . $r['first_name']) . '</td>'
               . '<td>' . e($r['program']) . '</td>'
               . ($registrarFilters ? '<td>' . e($r['department_code']) . '</td><td>' . e($r['school_year'] . ' · ' . label($r['semester'])) . '</td>' : '')
               . '<td>' . badge($r['status'])
               . ($r['remarks'] ? '<br><small>' . e($r['remarks']) . '</small>' : '') . '</td>'
               . '<td>' . e(fmt_date($r['reviewed_at'])) . '</td><td><div class="d-flex flex-column gap-2">'
               . form_open('', 'class="d-flex flex-column gap-2"') . '<input type="hidden" name="id" value="' . (int)$r['id'] . '">'
               . '<div class="w-100"><input class="form-control form-control-sm w-100" type="text" name="remarks" maxlength="255" placeholder="Remarks" aria-label="Remarks"></div>'
               . '<div class="d-flex flex-wrap gap-2"><button class="btn btn-success btn-sm" name="action" value="approve" type="submit">Approve</button>'
               . '<button class="btn btn-danger btn-sm" name="action" value="reject" type="submit">Reject</button></div></form>'
               . '</div></td></tr>';
        }
        echo '</tbody></table></div>';
    }
    render_footer();
}
