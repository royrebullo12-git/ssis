<?php
declare(strict_types=1);
define('SSIS_BOOT', true);
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../includes/layout.php';
$user = require_role('registrar');
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    post_guard();
    $program = post_str('program', 100);
    $yearLevel = post_int('year_level');
    $academicYear = post_str('academic_year', 9);
    $semester = post_str('semester', 6);
    $requiredUnits = post_int('required_units');
    $yearParts = explode('-', $academicYear);
    $validYear = count($yearParts) === 2 && ctype_digit($yearParts[0]) && ctype_digit($yearParts[1])
        && (int)$yearParts[1] === (int)$yearParts[0] + 1;
    if ($program === '' || $yearLevel < 1 || $yearLevel > 6 || !$validYear
        || !in_array($semester, ['1st', '2nd', 'summer'], true) || $requiredUnits < 1 || $requiredUnits > 40) {
        flash('error', 'Enter a program, valid year level and consecutive academic year, term, and requirement of 1–40 units.');
    } else {
        try {
            $pdo->prepare(
                'INSERT INTO curriculum_requirements (program, year_level, academic_year, semester, required_units)
                 VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE required_units = VALUES(required_units)'
            )->execute([$program, $yearLevel, $academicYear, $semester, $requiredUnits]);
            audit_log('CURRICULUM_REQUIREMENT_SAVED', (int)$user['id'], $user['username'], 'curriculum_requirement', null, "{$program}, year {$yearLevel}, {$academicYear} {$semester}: {$requiredUnits} units");
            flash('success', 'Curriculum unit requirement saved.');
        } catch (PDOException $e) {
            error_log('[WLS] curriculum requirement save failed: ' . $e->getMessage());
            flash('error', 'The curriculum requirement could not be saved.');
        }
    }
    redirect_self();
}

$requirements = $pdo->query(
    'SELECT program, year_level, academic_year, semester, required_units
       FROM curriculum_requirements
      ORDER BY program, year_level, academic_year DESC, FIELD(semester, "1st", "2nd", "summer")'
)->fetchAll();
render_header($user, 'Curriculum unit requirements');
?>
<section class="card">
  <p class="card-kicker">REGULAR / IRREGULAR CLASSIFICATION</p><h2>Configure expected units</h2>
  <p class="muted">The student portal compares enrolled units for a student’s program, year level, and term with this requirement. Saving the same program/year/term updates its unit target.</p>
  <?= form_open('save') ?>
    <div class="form-grid form-grid-3">
      <div><label for="program">Program name</label><input id="program" name="program" maxlength="100" required placeholder="BS Information Technology"></div>
      <div><label for="year_level">Year level</label><select id="year_level" name="year_level"><?php for ($level = 1; $level <= 6; $level++): ?><option value="<?= $level ?>"><?= $level ?></option><?php endfor; ?></select></div>
      <div><label for="academic_year">Academic year</label><input id="academic_year" name="academic_year" pattern="\d{4}-\d{4}" maxlength="9" value="<?= e(CURRENT_SY) ?>" required></div>
      <div><label for="semester">Term</label><select id="semester" name="semester"><?php foreach (['1st', '2nd', 'summer'] as $semester): ?><option value="<?= e($semester) ?>"<?= $semester === CURRENT_SEM ? ' selected' : '' ?>><?= e(label($semester)) ?></option><?php endforeach; ?></select></div>
      <div><label for="required_units">Expected units</label><input id="required_units" name="required_units" type="number" min="1" max="40" required></div>
    </div>
    <p class="form-actions"><button class="btn" type="submit">Save unit requirement</button></p>
  </form>
</section>
<section class="card">
  <h2>Configured curriculum requirements</h2>
  <?php if (!$requirements): ?><div class="empty">No curriculum requirements configured. Students will display as unconfigured until their program terms are added.</div><?php else: ?>
  <div class="tablewrap table-responsive"><table><thead><tr><th>Program</th><th>Year level</th><th>Academic year</th><th>Term</th><th>Expected units</th></tr></thead><tbody>
    <?php foreach ($requirements as $requirement): ?><tr>
      <td><?= e($requirement['program']) ?></td><td><?= (int)$requirement['year_level'] ?></td>
      <td><?= e($requirement['academic_year']) ?></td><td><?= e(label($requirement['semester'])) ?></td>
      <td><?= (int)$requirement['required_units'] ?></td>
    </tr><?php endforeach; ?>
  </tbody></table></div><?php endif; ?>
</section>
<?php render_footer(); ?>
