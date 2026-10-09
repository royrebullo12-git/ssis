<?php
declare(strict_types=1);
define('SSIS_BOOT', true);
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../includes/layout.php';
$user = require_role('department');
$pdo = db();
$departmentId = (int)($user['department_id'] ?? 0);
if ($departmentId < 1) {
    http_response_code(403);
    exit('Your account is not linked to a department.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    post_guard();
    $action = post_str('action', 24);
    try {
        if ($action === 'save_professor') {
            $id = post_int('id');
            $first = post_str('first_name', 60);
            $last = post_str('last_name', 60);
            $email = post_str('email', 120);
            if ($first === '' || $last === '' || ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL))) {
                flash('error', 'Enter a first name, last name, and a valid email address if provided.');
            } elseif ($id) {
                $update = $pdo->prepare('UPDATE professors SET first_name = ?, last_name = ?, email = ? WHERE id = ? AND department_id = ?');
                $update->execute([$first, $last, $email ?: null, $id, $departmentId]);
                flash($update->rowCount() ? 'success' : 'error', $update->rowCount() ? 'Faculty profile saved.' : 'Faculty profile not found or unchanged.');
            } else {
                $pdo->prepare('INSERT INTO professors (department_id, first_name, last_name, email) VALUES (?, ?, ?, ?)')
                    ->execute([$departmentId, $first, $last, $email ?: null]);
                flash('success', 'Faculty profile created. Ask an Admin to provision portal access if required.');
            }
        } elseif ($action === 'toggle_professor') {
            $update = $pdo->prepare("UPDATE professors SET status = IF(status = 'active', 'inactive', 'active') WHERE id = ? AND department_id = ?");
            $update->execute([post_int('id'), $departmentId]);
            flash($update->rowCount() ? 'success' : 'error', $update->rowCount() ? 'Faculty status updated.' : 'Faculty profile not found.');
        } elseif ($action === 'save_offering') {
            $id = post_int('id');
            $subjectId = post_int('subject_id');
            $professorId = post_int('professor_id');
            $year = post_str('academic_year', 9);
            $semester = post_str('semester', 6);
            $section = post_str('section', 30);
            $schedule = post_str('schedule', 120);
            $valid = $pdo->prepare(
                "SELECT COUNT(*) FROM subjects s JOIN professors p ON p.department_id = s.department_id
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
                       FROM subject_offerings o JOIN subjects s ON s.id = o.subject_id
                       LEFT JOIN enrollments e ON e.subject_offering_id = o.id
                      WHERE o.id = ? AND s.department_id = ?
                      GROUP BY o.id, o.subject_id, o.academic_year, o.semester, o.section'
                );
                $current->execute([$id, $departmentId]);
                $offering = $current->fetch();
                $identityChanged = $offering && ((int)$offering['subject_id'] !== $subjectId
                    || $offering['academic_year'] !== $year || $offering['semester'] !== $semester || $offering['section'] !== $section);
                if (!$offering) {
                    flash('error', 'Offering not found.');
                } elseif ((int)$offering['enrollment_count'] > 0 && $identityChanged) {
                    flash('error', 'Subject, term, and section cannot be changed after students have enrolled.');
                } else {
                    $update = $pdo->prepare(
                        'UPDATE subject_offerings o JOIN subjects s ON s.id = o.subject_id
                            SET o.subject_id = ?, o.professor_id = ?, o.academic_year = ?, o.semester = ?, o.section = ?, o.schedule = ?
                          WHERE o.id = ? AND s.department_id = ?'
                    );
                    $update->execute([$subjectId, $professorId, $year, $semester, $section, $schedule ?: null, $id, $departmentId]);
                    flash('success', 'Instructor assignment saved.');
                }
            } else {
                $pdo->prepare(
                    'INSERT INTO subject_offerings (subject_id, professor_id, academic_year, semester, section, schedule)
                     VALUES (?, ?, ?, ?, ?, ?)'
                )->execute([$subjectId, $professorId, $year, $semester, $section, $schedule ?: null]);
                flash('success', 'Instructor assignment created.');
            }
        } elseif ($action === 'toggle_offering') {
            $update = $pdo->prepare(
                'UPDATE subject_offerings o JOIN subjects s ON s.id = o.subject_id
                    SET o.is_active = IF(o.is_active = 1, 0, 1)
                  WHERE o.id = ? AND s.department_id = ?'
            );
            $update->execute([post_int('id'), $departmentId]);
            flash($update->rowCount() ? 'success' : 'error', $update->rowCount() ? 'Offering status updated.' : 'Offering not found.');
        } else {
            flash('error', 'Unknown faculty-management action.');
        }
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            flash('error', 'That subject offering already exists for the selected term and section.');
        } else {
            error_log('[WLS] department faculty management failed: ' . $e->getMessage());
            flash('error', 'The faculty or instructor assignment could not be saved.');
        }
    }
    redirect_self();
}

$query = $pdo->prepare('SELECT * FROM professors WHERE department_id = ? ORDER BY last_name, first_name');
$query->execute([$departmentId]);
$faculty = $query->fetchAll();
$query = $pdo->prepare('SELECT * FROM subjects WHERE department_id = ? ORDER BY code');
$query->execute([$departmentId]);
$subjects = $query->fetchAll();
$activeSubjects = array_filter($subjects, static fn(array $subject): bool => (int)$subject['is_active'] === 1);
$activeFaculty = array_filter($faculty, static fn(array $person): bool => $person['status'] === 'active');
$query = $pdo->prepare(
    'SELECT o.*, s.code, s.title, p.first_name, p.last_name
       FROM subject_offerings o JOIN subjects s ON s.id = o.subject_id
       JOIN professors p ON p.id = o.professor_id
      WHERE s.department_id = ? ORDER BY o.academic_year DESC, o.semester, s.code, o.section'
);
$query->execute([$departmentId]);
$offerings = $query->fetchAll();

render_header($user, 'Faculty profiles and instructor assignments');
?>
<div class="faculty-setup-grid">
<section class="card">
  <p class="card-kicker">FACULTY DIRECTORY</p><h2>Add a faculty profile</h2>
  <?= form_open('save_professor') ?>
    <div class="form-grid form-grid-3">
      <div><label for="first_name">First name</label><input id="first_name" name="first_name" maxlength="60" required></div>
      <div class="faculty-last-name"><label for="last_name">Last name</label><input id="last_name" name="last_name" maxlength="60" required></div>
      <div><label for="email">Email</label><input id="email" name="email" type="email" maxlength="120"></div>
    </div>
    <p class="form-actions"><button class="btn" type="submit">Add faculty profile</button></p>
  </form>
</section>
<section class="card">
  <h2>Faculty profiles</h2>
  <?php if (!$faculty): ?><div class="empty">No faculty profiles are linked to this department.</div><?php else: ?>
  <div class="tablewrap table-responsive"><table><thead><tr><th>Faculty member</th><th>Email</th><th>Portal access</th><th>Status</th><th>Update</th></tr></thead><tbody>
  <?php foreach ($faculty as $person): ?><tr>
    <td><?= e($person['last_name'] . ', ' . $person['first_name']) ?></td><td><?= e($person['email'] ?? '—') ?></td>
    <td><?= $person['user_id'] ? 'Linked' : 'Not linked' ?></td><td><?= badge($person['status']) ?></td>
    <td><div class="actions"><?= form_open('save_professor') ?><input type="hidden" name="id" value="<?= (int)$person['id'] ?>">
      <input type="text" name="first_name" value="<?= e($person['first_name']) ?>" aria-label="First name" required>
      <input type="text" name="last_name" value="<?= e($person['last_name']) ?>" aria-label="Last name" required>
      <input type="email" name="email" value="<?= e($person['email'] ?? '') ?>" aria-label="Email">
      <button class="btn alt sm" type="submit">Save</button></form>
      <?= form_open('toggle_professor') ?><input type="hidden" name="id" value="<?= (int)$person['id'] ?>">
      <button class="btn alt sm" type="submit"><?= $person['status'] === 'active' ? 'Deactivate' : 'Activate' ?></button></form></div></td>
  </tr><?php endforeach; ?>
  </tbody></table></div><?php endif; ?>
</section>
<section class="card">
  <p class="card-kicker">INSTRUCTOR ASSIGNMENTS</p><h2>Create a subject offering</h2>
  <?php if (!$activeSubjects || !$activeFaculty): ?><p class="empty">Create or activate a department subject and faculty profile before making an assignment.</p>
  <?php else: ?>
    <?= form_open('save_offering') ?>
      <div class="form-grid form-grid-3">
        <div><label for="subject_id">Subject</label><select id="subject_id" name="subject_id" required>
          <?php foreach ($activeSubjects as $subject): ?><option value="<?= (int)$subject['id'] ?>"><?= e($subject['code'] . ' - ' . $subject['title']) ?></option><?php endforeach; ?>
        </select></div>
        <div><label for="professor_id">Instructor</label><select id="professor_id" name="professor_id" required>
          <?php foreach ($activeFaculty as $person): ?><option value="<?= (int)$person['id'] ?>"><?= e($person['last_name'] . ', ' . $person['first_name']) ?></option><?php endforeach; ?>
        </select></div>
        <div><label for="academic_year">Academic year</label><input id="academic_year" name="academic_year" type="text" pattern="\d{4}-\d{4}" maxlength="9" value="<?= e(CURRENT_SY) ?>" required></div>
        <div><label for="semester">Term</label><select id="semester" name="semester"><?php foreach (['1st', '2nd', 'summer'] as $semester): ?><option value="<?= e($semester) ?>"<?= $semester === CURRENT_SEM ? ' selected' : '' ?>><?= e($semester) ?></option><?php endforeach; ?></select></div>
        <div><label for="section">Section</label><input id="section" name="section" maxlength="30" required></div>
        <div><label for="schedule">Schedule</label><input id="schedule" name="schedule" maxlength="120"></div>
      </div>
      <p class="form-actions"><button class="btn" type="submit">Create instructor assignment</button></p>
    </form>
  <?php endif; ?>
</section>
</div>
<section class="card faculty-assignment-list">
  <h2>Subject offerings and assignments</h2>
  <?php if (!$offerings): ?><div class="empty">No instructor assignments have been created.</div><?php else: ?>
  <div class="tablewrap table-responsive"><table><thead><tr><th>Offering</th><th>Term</th><th>Instructor assignment</th><th>Status</th><th>Manage assignment</th></tr></thead><tbody>
  <?php foreach ($offerings as $offering): ?><tr>
    <td><?= e($offering['code'] . ' - ' . $offering['title'] . ' / ' . $offering['section']) ?></td>
    <td><?= e($offering['academic_year'] . ' · ' . $offering['semester']) ?><br><small><?= e($offering['schedule'] ?? 'Schedule not set') ?></small></td>
    <td><?= e($offering['last_name'] . ', ' . $offering['first_name']) ?></td>
    <td><div class="actions"><?= badge((int)$offering['is_active'] ? 'active' : 'inactive') ?>
      <?= form_open('toggle_offering') ?><input type="hidden" name="id" value="<?= (int)$offering['id'] ?>">
      <button class="btn alt sm" type="submit"><?= (int)$offering['is_active'] ? 'Deactivate' : 'Activate' ?></button></form></div></td>
    <td><details><summary>Edit assignment</summary><?= form_open('save_offering') ?>
      <input type="hidden" name="id" value="<?= (int)$offering['id'] ?>">
      <div class="form-grid">
        <div><label for="edit-subject-<?= (int)$offering['id'] ?>">Subject</label><select id="edit-subject-<?= (int)$offering['id'] ?>" name="subject_id" required>
          <?php foreach ($activeSubjects as $subject): ?><option value="<?= (int)$subject['id'] ?>"<?= (int)$subject['id'] === (int)$offering['subject_id'] ? ' selected' : '' ?>><?= e($subject['code'] . ' - ' . $subject['title']) ?></option><?php endforeach; ?>
        </select></div>
        <div><label for="edit-professor-<?= (int)$offering['id'] ?>">Instructor</label><select id="edit-professor-<?= (int)$offering['id'] ?>" name="professor_id" required>
          <?php foreach ($activeFaculty as $person): ?><option value="<?= (int)$person['id'] ?>"<?= (int)$person['id'] === (int)$offering['professor_id'] ? ' selected' : '' ?>><?= e($person['last_name'] . ', ' . $person['first_name']) ?></option><?php endforeach; ?>
        </select></div>
        <div><label for="edit-year-<?= (int)$offering['id'] ?>">Academic year</label><input id="edit-year-<?= (int)$offering['id'] ?>" name="academic_year" pattern="\d{4}-\d{4}" maxlength="9" value="<?= e($offering['academic_year']) ?>" required></div>
        <div><label for="edit-term-<?= (int)$offering['id'] ?>">Term</label><select id="edit-term-<?= (int)$offering['id'] ?>" name="semester"><?php foreach (['1st', '2nd', 'summer'] as $semester): ?><option value="<?= e($semester) ?>"<?= $semester === $offering['semester'] ? ' selected' : '' ?>><?= e(label($semester)) ?></option><?php endforeach; ?></select></div>
        <div><label for="edit-section-<?= (int)$offering['id'] ?>">Section</label><input id="edit-section-<?= (int)$offering['id'] ?>" name="section" maxlength="30" value="<?= e($offering['section']) ?>" required></div>
        <div><label for="edit-schedule-<?= (int)$offering['id'] ?>">Schedule</label><input id="edit-schedule-<?= (int)$offering['id'] ?>" name="schedule" maxlength="120" value="<?= e($offering['schedule'] ?? '') ?>"></div>
      </div><button class="btn alt sm" type="submit">Save assignment</button></form></details></td>
  </tr><?php endforeach; ?>
  </tbody></table></div><?php endif; ?>
</section>
<?php render_footer(); ?>
