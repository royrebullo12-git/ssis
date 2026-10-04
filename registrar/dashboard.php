<?php
declare(strict_types=1);
define('SSIS_BOOT', true);
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../includes/layout.php';
$user = require_role('registrar');
$pdo  = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    post_guard();
    if (post_str('action', 20) === 'generate') {
        // One registrar, one cashier and one department clearance per student for the current term.
        $n = 0;
        $ins = $pdo->prepare('INSERT IGNORE INTO clearances (student_id, clearance_type, department_id, school_year, semester) VALUES (?, ?, ?, ?, ?)');
        foreach ($pdo->query('SELECT id, department_id FROM students')->fetchAll() as $s) {
            foreach ([['registrar', null], ['cashier', null], ['department', (int)$s['department_id']]] as [$t, $d]) {
                $ins->execute([$s['id'], $t, $d, CURRENT_SY, CURRENT_SEM]);
                $n += $ins->rowCount();
            }
        }
        audit_log('CLEARANCES_GENERATED', (int)$user['id'], $user['username'], 'clearance', null, CURRENT_SY . ' ' . CURRENT_SEM . ": {$n} new");
        flash('success', "{$n} clearance record(s) created for " . CURRENT_SY . ', ' . CURRENT_SEM . ' semester.');
    }
    redirect_self();
}

$one = fn(string $sql, array $p = []) => (function () use ($pdo, $sql, $p) { $s = $pdo->prepare($sql); $s->execute($p); return (int)$s->fetchColumn(); })();
$students = $one('SELECT COUNT(*) FROM students');
$pendCl   = $one("SELECT COUNT(*) FROM clearances WHERE clearance_type = 'registrar' AND status = 'pending' AND school_year = ? AND semester = ?", [CURRENT_SY, CURRENT_SEM]);
$pendReq  = $one("SELECT COUNT(*) FROM document_requests WHERE status IN ('submitted','processing','ready')");
$pendGr   = $one("SELECT COUNT(*) FROM grades WHERE remarks = 'pending'");

render_header($user, 'Registrar dashboard');
?>
<div class="grid">
  <div class="stat"><b><?= $students ?></b><span>Student records</span></div>
  <div class="stat"><b><?= $pendCl ?></b><span>Registrar clearances pending</span></div>
  <div class="stat"><b><?= $pendReq ?></b><span>Document requests to act on</span></div>
  <div class="stat"><b><?= $pendGr ?></b><span>Grades not yet encoded</span></div>
</div>
<div class="card"><h2>Start of term</h2>
  <p>Create the clearance records every student needs for <?= e(CURRENT_SY) ?>, <?= e(CURRENT_SEM) ?> semester. Existing records are left alone.</p>
  <?= form_open('generate') ?><button class="btn" type="submit" data-confirm="Create clearance records for all students?">Generate clearances</button></form>
</div>
<?php render_footer();
