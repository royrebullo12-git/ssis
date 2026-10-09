<?php
declare(strict_types=1);
define('SSIS_BOOT', true);
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../includes/layout.php';
$user = require_role('admin');
$pdo = db();

$depts = $pdo->query('SELECT id, code, name FROM departments ORDER BY code')->fetchAll();
$deptOpts = '<option value="">None</option>';
foreach ($depts as $department) {
    $deptOpts .= '<option value="' . (int)$department['id'] . '">' . e($department['code'] . ' - ' . $department['name']) . '</option>';
}

render_header($user, 'Create account');
?>
<section class="card">
  <h2>Create an account</h2>
  <form method="post" action="<?= e(url('/admin/users.php')) ?>" class="account-create-form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create">
    <div class="account-details-grid">
      <div class="account-username-slot"><label for="username">Username</label><input id="username" name="username" type="text" maxlength="50" autocomplete="off" data-account-username></div>
      <div class="account-email-field"><label for="email">Email</label><input id="email" name="email" type="email" maxlength="120" required></div>
      <div class="account-mobile-field"><label for="mobile_phone">Mobile number</label><input id="mobile_phone" name="mobile_phone" type="tel" maxlength="20" required autocomplete="tel" placeholder="+639171234567"></div>
      <div class="account-role-field"><label for="role">Role</label><select id="role" name="role" required><?php foreach (ALL_ROLES as $r): ?><option value="<?= $r ?>"><?= e(label($r)) ?></option><?php endforeach; ?></select></div>
      <div class="account-department-field"><label for="department_id">Department (students, professors and department staff)</label><select id="department_id" name="department_id"><?= $deptOpts ?></select></div>
      <div class="account-program-field"><label for="program">Program (students)</label><input id="program" name="program" type="text" maxlength="100"></div>
      <div class="account-first-field"><label for="first_name">First name (students / professors)</label><input id="first_name" name="first_name" type="text" maxlength="60"></div>
      <div class="account-last-field"><label for="last_name">Last name (students / professors)</label><input id="last_name" name="last_name" type="text" maxlength="60"></div>
      <div class="account-year-field"><label for="year_level">Year level (students)</label><input id="year_level" name="year_level" type="number" min="1" max="6" value="1"></div>
      <div class="account-admission-field" data-student-only hidden><label for="admission_year">Admission school year</label><input id="admission_year" name="admission_year" type="text" maxlength="9" pattern="\d{4}-\d{4}" value="<?= e(CURRENT_SY) ?>"><small class="field-help">The first year sets the generated student ID prefix.</small></div>
      <div class="account-form-submit">
        <button class="btn" type="submit">Create account and send credentials</button>
        <small>A secure temporary password is sent by SMS to the registered mobile number.</small>
      </div>
    </div>
  </form>
</section>
<?php render_footer();
