<?php
declare(strict_types=1);
define('SSIS_BOOT', true);
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../includes/layout.php';
$user = require_role('department');
$pdo = db();
$departmentId = (int)($user['department_id'] ?? 0);
if ($departmentId < 1) { http_response_code(403); exit('Your account is not linked to a department.'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    post_guard();
    $action = post_str('action', 24);
    try {
        if ($action === 'save_subject') {
            $id = post_int('id');
            $code = strtoupper(post_str('code', 15));
            $title = post_str('title', 120);
            $units = post_int('units');
            if (!preg_match('/^[A-Z0-9-]{2,15}$/', $code) || $title === '' || $units < 1 || $units > 30) {
                flash('error', 'Enter a valid subject code, title, and units (1-30).');
            } elseif ($id) {
                $exists = $pdo->prepare('SELECT 1 FROM subjects WHERE id = ? AND department_id = ?');
                $exists->execute([$id, $departmentId]);
                if (!$exists->fetchColumn()) {
                    flash('error', 'Subject not found.');
                } else {
                    $pdo->prepare('UPDATE subjects SET code = ?, title = ?, units = ? WHERE id = ? AND department_id = ?')
                        ->execute([$code, $title, $units, $id, $departmentId]);
                    flash('success', 'Subject saved.');
                }
            } else {
                $pdo->prepare('INSERT INTO subjects (code, title, units, department_id) VALUES (?, ?, ?, ?)')
                    ->execute([$code, $title, $units, $departmentId]);
                flash('success', 'Subject created.');
            }
        } elseif ($action === 'toggle_subject') {
            $st = $pdo->prepare('UPDATE subjects SET is_active = IF(is_active = 1, 0, 1) WHERE id = ? AND department_id = ?');
            $st->execute([post_int('id'), $departmentId]);
            flash($st->rowCount() ? 'success' : 'error', $st->rowCount() ? 'Subject status updated.' : 'Subject not found.');
        } elseif ($action === 'save_professor') {
            $id = post_int('id');
            $first = post_str('first_name', 60);
            $last = post_str('last_name', 60);
            $email = post_str('email', 120);
            if ($first === '' || $last === '' || ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL))) {
                flash('error', 'Enter a first name, last name, and a valid email address if provided.');
            } elseif ($id) {
                $exists = $pdo->prepare('SELECT 1 FROM professors WHERE id = ? AND department_id = ?');
                $exists->execute([$id, $departmentId]);
                if (!$exists->fetchColumn()) {
                    flash('error', 'Faculty profile not found.');
                } else {
                    $pdo->prepare('UPDATE professors SET first_name = ?, last_name = ?, email = ? WHERE id = ? AND department_id = ?')
                        ->execute([$first, $last, $email ?: null, $id, $departmentId]);
                    flash('success', 'Faculty profile saved.');
                }
            } else {
                $pdo->prepare('INSERT INTO professors (department_id, first_name, last_name, email) VALUES (?, ?, ?, ?)')
                    ->execute([$departmentId, $first, $last, $email ?: null]);
                flash('success', 'Faculty profile created. Ask an Admin to provision portal access if needed.');
            }
        } elseif ($action === 'toggle_professor') {
            $st = $pdo->prepare("UPDATE professors SET status = IF(status = 'active', 'inactive', 'active') WHERE id = ? AND department_id = ?");
            $st->execute([post_int('id'), $departmentId]);
            flash($st->rowCount() ? 'success' : 'error', $st->rowCount() ? 'Faculty status updated.' : 'Faculty profile not found.');
        } elseif ($action === 'save_offering') {
            $id = post_int('id');
            $subjectId = post_int('subject_id');
            $professorId = post_int('professor_id');
            $year = post_str('academic_year', 9);
            $semester = post_str('semester', 6);
            $section = post_str('section', 30);
            $schedule = post_str('schedule', 120);
            $valid = $pdo->prepare(
                "SELECT COUNT(*) FROM subjects s
                 JOIN professors p ON p.department_id = s.department_id
                 WHERE s.id = ? AND p.id = ? AND s.department_id = ?
                   AND s.is_active = 1 AND p.status = 'active'"
            );
            $valid->execute([$subjectId, $professorId, $departmentId]);
            if ((int)$valid->fetchColumn() !== 1 || !preg_match('/^\d{4}-\d{4}$/', $year)
                || !in_array($semester, ['1st', '2nd', 'summer'], true) || $section === '') {
                flash('error', 'Choose active department subjects and faculty, and provide a valid term and section.');
            } elseif ($id) {
                $current = $pdo->prepare(
                    'SELECT o.subject_id, o.academic_year, o.semester, o.section, COUNT(e.id) AS enrollment_count
                       FROM subject_offerings o
                       JOIN subjects s ON s.id = o.subject_id
                       LEFT JOIN enrollments e ON e.subject_offering_id = o.id
                      WHERE o.id = ? AND s.department_id = ?
                      GROUP BY o.id, o.subject_id, o.academic_year, o.semester, o.section'
                );
                $current->execute([$id, $departmentId]);
                $offering = $current->fetch();
                $identityChanged = $offering && (
                    (int)$offering['subject_id'] !== $subjectId
                    || $offering['academic_year'] !== $year
                    || $offering['semester'] !== $semester
                    || $offering['section'] !== $section
                );
                if (!$offering) {
                    flash('error', 'Offering not found.');
                } elseif ((int)$offering['enrollment_count'] > 0 && $identityChanged) {
                    flash('error', 'Subject, term, and section cannot be changed after students have enrolled.');
                } else {
                    $st = $pdo->prepare(
                        'UPDATE subject_offerings o JOIN subjects s ON s.id = o.subject_id
                            SET o.subject_id = ?, o.professor_id = ?, o.academic_year = ?, o.semester = ?, o.section = ?, o.schedule = ?
                          WHERE o.id = ? AND s.department_id = ?'
                    );
                    $st->execute([$subjectId, $professorId, $year, $semester, $section, $schedule ?: null, $id, $departmentId]);
                    flash('success', 'Offering saved.');
                }
            } else {
                $pdo->prepare(
                    'INSERT INTO subject_offerings (subject_id, professor_id, academic_year, semester, section, schedule)
                     VALUES (?, ?, ?, ?, ?, ?)'
                )->execute([$subjectId, $professorId, $year, $semester, $section, $schedule ?: null]);
                flash('success', 'Subject offering created.');
            }
        } elseif ($action === 'toggle_offering') {
            $st = $pdo->prepare(
                'UPDATE subject_offerings o JOIN subjects s ON s.id = o.subject_id
                    SET o.is_active = IF(o.is_active = 1, 0, 1)
                  WHERE o.id = ? AND s.department_id = ?'
            );
            $st->execute([post_int('id'), $departmentId]);
            flash($st->rowCount() ? 'success' : 'error', $st->rowCount() ? 'Offering status updated.' : 'Offering not found.');
        } else {
            flash('error', 'Unknown academic-management action.');
        }
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') flash('error', 'That subject code or offering section already exists.');
        else { error_log('[SSIS] department/academics: ' . $e->getMessage()); flash('error', 'The change could not be saved.'); }
    }
    redirect_self();
}

$subjectsStmt = $pdo->prepare('SELECT * FROM subjects WHERE department_id = ? ORDER BY code');
$subjectsStmt->execute([$departmentId]);
$subjects = $subjectsStmt->fetchAll();
$professorsStmt = $pdo->prepare('SELECT * FROM professors WHERE department_id = ? ORDER BY last_name, first_name');
$professorsStmt->execute([$departmentId]);
$professors = $professorsStmt->fetchAll();
$offeringsStmt = $pdo->prepare(
    'SELECT o.*, s.code, s.title, p.first_name, p.last_name
       FROM subject_offerings o
       JOIN subjects s ON s.id = o.subject_id
       JOIN professors p ON p.id = o.professor_id
      WHERE s.department_id = ? ORDER BY o.academic_year DESC, o.semester, s.code, o.section'
);
$offeringsStmt->execute([$departmentId]);
$offerings = $offeringsStmt->fetchAll();
$activeSubjects = array_filter($subjects, static fn(array $subject): bool => (int)$subject['is_active'] === 1);
$activeProfessors = array_filter($professors, static fn(array $professor): bool => $professor['status'] === 'active');

render_header($user, 'Department academic setup');
?>
<div class="content-grid">
  <section class="card"><h2>Create a subject</h2>
    <?= form_open('save_subject') ?><div class="row">
      <div><label for="code">Subject code</label><input id="code" name="code" type="text" maxlength="15" required></div>
      <div><label for="title">Title</label><input id="title" name="title" type="text" maxlength="120" required></div>
      <div><label for="units">Units</label><input id="units" name="units" type="number" min="1" max="30" value="3" required></div>
    </div><p><button class="btn" type="submit">Create subject</button></p></form>
  </section>
  <section class="card"><h2>Add a faculty profile</h2>
    <?= form_open('save_professor') ?><div class="row">
      <div><label for="first_name">First name</label><input id="first_name" name="first_name" type="text" maxlength="60" required></div>
      <div><label for="last_name">Last name</label><input id="last_name" name="last_name" type="text" maxlength="60" required></div>
      <div><label for="email">Email</label><input id="email" name="email" type="email" maxlength="120"></div>
    </div><p><button class="btn" type="submit">Add faculty</button></p></form>
  </section>
</div>
<div class="card"><h2>Subject offerings and sectioning</h2>
  <?php if (!$activeSubjects || !$activeProfessors): ?><p class="empty">Create or activate a subject and faculty profile before creating an offering.</p>
  <?php else: ?>
  <?= form_open('save_offering') ?><div class="row">
    <div><label for="subject_id">Subject</label><select id="subject_id" name="subject_id" required>
      <?php foreach ($activeSubjects as $subject): ?><option value="<?= (int)$subject['id'] ?>"><?= e($subject['code'] . ' - ' . $subject['title']) ?></option><?php endforeach; ?>
    </select></div>
    <div><label for="professor_id">Professor</label><select id="professor_id" name="professor_id" required>
      <?php foreach ($activeProfessors as $professor): ?><option value="<?= (int)$professor['id'] ?>"><?= e($professor['last_name'] . ', ' . $professor['first_name']) ?></option><?php endforeach; ?>
    </select></div>
    <div><label for="academic_year">Academic year</label><input id="academic_year" name="academic_year" type="text" pattern="\d{4}-\d{4}" maxlength="9" value="<?= e(CURRENT_SY) ?>" required></div>
    <div><label for="semester">Semester</label><select id="semester" name="semester"><?php foreach (['1st', '2nd', 'summer'] as $semester): ?><option value="<?= e($semester) ?>"<?= $semester === CURRENT_SEM ? ' selected' : '' ?>><?= e($semester) ?></option><?php endforeach; ?></select></div>
    <div><label for="section">Section</label><input id="section" name="section" type="text" maxlength="30" required></div>
    <div><label for="schedule">Schedule</label><input id="schedule" name="schedule" type="text" maxlength="120"></div>
  </div><p><button class="btn" type="submit">Create offering</button></p></form>
  <?php endif; ?>
</div>
<div class="card"><h2>Department subjects</h2><div class="tablewrap"><table><thead><tr><th>Code</th><th>Title</th><th>Units</th><th>Status</th><th>Update</th><th></th></tr></thead><tbody>
<?php foreach ($subjects as $subject): ?><tr><td><?= e($subject['code']) ?></td><td><?= e($subject['title']) ?></td><td><?= (int)$subject['units'] ?></td><td><?= badge((int)$subject['is_active'] ? 'active' : 'inactive') ?></td>
  <td><?= form_open('save_subject') ?><input type="hidden" name="id" value="<?= (int)$subject['id'] ?>"><input type="text" name="code" value="<?= e($subject['code']) ?>" aria-label="Subject code" required><input type="text" name="title" value="<?= e($subject['title']) ?>" aria-label="Subject title" required><input type="number" name="units" min="1" max="30" value="<?= (int)$subject['units'] ?>" aria-label="Units" required><button class="btn alt sm" type="submit">Save</button></form></td>
  <td><?= form_open('toggle_subject') ?><input type="hidden" name="id" value="<?= (int)$subject['id'] ?>"><button class="btn alt sm" type="submit"><?= (int)$subject['is_active'] ? 'Deactivate' : 'Activate' ?></button></form></td>
</tr><?php endforeach; ?>
</tbody></table></div></div>
<div class="card"><h2>Faculty profiles</h2><div class="tablewrap"><table><thead><tr><th>Professor</th><th>Email</th><th>Portal account</th><th>Status</th><th>Update</th><th></th></tr></thead><tbody>
<?php foreach ($professors as $professor): ?><tr><td><?= e($professor['last_name'] . ', ' . $professor['first_name']) ?></td><td><?= e($professor['email'] ?? '-') ?></td><td><?= $professor['user_id'] ? 'Linked' : 'None' ?></td><td><?= badge($professor['status']) ?></td>
  <td><?= form_open('save_professor') ?><input type="hidden" name="id" value="<?= (int)$professor['id'] ?>"><input type="text" name="first_name" value="<?= e($professor['first_name']) ?>" aria-label="First name" required><input type="text" name="last_name" value="<?= e($professor['last_name']) ?>" aria-label="Last name" required><input type="email" name="email" value="<?= e($professor['email'] ?? '') ?>" aria-label="Email"><button class="btn alt sm" type="submit">Save</button></form></td>
  <td><?= form_open('toggle_professor') ?><input type="hidden" name="id" value="<?= (int)$professor['id'] ?>"><button class="btn alt sm" type="submit"><?= $professor['status'] === 'active' ? 'Deactivate' : 'Activate' ?></button></form></td>
</tr><?php endforeach; ?>
</tbody></table></div><p><small>Professor login accounts are provisioned by Admin and linked to a faculty profile.</small></p></div>
<div class="card"><h2>Offerings</h2><div class="tablewrap"><table><thead><tr><th>Current offering</th><th>Edit offering</th><th>Status</th></tr></thead><tbody>
<?php foreach ($offerings as $offering): ?><tr>
  <td><?= e($offering['code'] . ' - ' . $offering['title'] . ' / ' . $offering['last_name'] . ', ' . $offering['first_name'] . ' / ' . $offering['academic_year'] . ' ' . $offering['semester'] . ' / ' . $offering['section'] . ' / ' . ($offering['schedule'] ?? '-')) ?></td>
  <td><?= form_open('save_offering') ?><input type="hidden" name="id" value="<?= (int)$offering['id'] ?>">
    <select name="subject_id" aria-label="Subject"><?php foreach ($subjects as $subject): ?><option value="<?= (int)$subject['id'] ?>"<?= (int)$subject['id'] === (int)$offering['subject_id'] ? ' selected' : '' ?>><?= e($subject['code'] . ((int)$subject['is_active'] ? '' : ' (inactive)')) ?></option><?php endforeach; ?></select>
    <select name="professor_id" aria-label="Professor"><?php foreach ($professors as $faculty): ?><option value="<?= (int)$faculty['id'] ?>"<?= (int)$faculty['id'] === (int)$offering['professor_id'] ? ' selected' : '' ?>><?= e($faculty['last_name'] . ', ' . $faculty['first_name'] . ($faculty['status'] === 'active' ? '' : ' (inactive)')) ?></option><?php endforeach; ?></select>
    <input type="text" name="academic_year" value="<?= e($offering['academic_year']) ?>" pattern="\d{4}-\d{4}" maxlength="9" aria-label="Academic year" required>
    <select name="semester" aria-label="Semester"><?php foreach (['1st', '2nd', 'summer'] as $semester): ?><option value="<?= e($semester) ?>"<?= $semester === $offering['semester'] ? ' selected' : '' ?>><?= e($semester) ?></option><?php endforeach; ?></select>
    <input type="text" name="section" value="<?= e($offering['section']) ?>" maxlength="30" aria-label="Section" required>
    <input type="text" name="schedule" value="<?= e($offering['schedule'] ?? '') ?>" maxlength="120" aria-label="Schedule">
    <button class="btn alt sm" type="submit">Save</button></form>
  </td><td><?= badge((int)$offering['is_active'] ? 'active' : 'inactive') ?><br><?= form_open('toggle_offering') ?><input type="hidden" name="id" value="<?= (int)$offering['id'] ?>"><button class="btn alt sm" type="submit"><?= (int)$offering['is_active'] ? 'Deactivate' : 'Activate' ?></button></form></td>
</tr><?php endforeach; ?>
</tbody></table></div><p><a class="btn alt" href="<?= e(url('/department/classes.php')) ?>">View live class lists</a></p></div>
<?php render_footer();
