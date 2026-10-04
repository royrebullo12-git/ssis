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

    $sql = "SELECT c.id, c.status, c.remarks, c.reviewed_at, s.student_no, s.first_name, s.last_name, s.program
              FROM clearances c JOIN students s ON s.id = c.student_id
             WHERE c.clearance_type = ? AND {$scopeSql} AND c.school_year = ? AND c.semester = ?";
    $params = array_merge([$type], $scopeParams, [CURRENT_SY, CURRENT_SEM]);
    if ($status !== 'all') { $sql .= ' AND c.status = ?'; $params[] = $status; }
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
    echo '<p class="muted">Term: ' . e(CURRENT_SY) . ', ' . e(CURRENT_SEM) . ' semester'
       . ($type === 'cashier' ? '. A clearance can only be approved when the student has no unpaid balance.' : '') . '</p>';
    echo '<form class="filters" method="get"><div><label for="q">Search</label><input id="q" name="q" type="text" value="' . e($q) . '" placeholder="Student no. or name"></div>'
       . '<div><label for="status">Status</label><select id="status" name="status">';
    foreach (['pending', 'approved', 'rejected', 'all'] as $s) {
        echo '<option value="' . $s . '"' . ($s === $status ? ' selected' : '') . '>' . e(label($s)) . '</option>';
    }
    echo '</select></div><button class="btn" type="submit">Filter</button></form>';

    if (!$rows) {
        echo '<div class="card empty">No clearances match these filters.</div>';
    } else {
        echo '<div class="tablewrap"><table><thead><tr><th>Student</th><th>Program</th><th>Status</th><th>Reviewed</th><th>Decision</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            echo '<tr><td>' . e($r['student_no']) . '<br>' . e($r['last_name'] . ', ' . $r['first_name']) . '</td>'
               . '<td>' . e($r['program']) . '</td><td>' . badge($r['status'])
               . ($r['remarks'] ? '<br><small>' . e($r['remarks']) . '</small>' : '') . '</td>'
               . '<td>' . e(fmt_date($r['reviewed_at'])) . '</td><td><div class="actions">'
               . form_open() . '<input type="hidden" name="id" value="' . (int)$r['id'] . '">'
               . '<input type="text" name="remarks" maxlength="255" placeholder="Remarks" aria-label="Remarks">'
               . '<button class="btn ok sm" name="action" value="approve" type="submit">Approve</button>'
               . '<button class="btn danger sm" name="action" value="reject" type="submit">Reject</button></form>'
               . '</div></td></tr>';
        }
        echo '</tbody></table></div>';
    }
    render_footer();
}
