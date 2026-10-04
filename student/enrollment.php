<?php
declare(strict_types=1);
define('SSIS_BOOT', true);
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../includes/layout.php';
$user = require_role('student');
$stu  = student_of($user);
$pdo  = db();

const STEPS = ['not_enrolled' => 'Not enrolled', 'queued' => 'In queue', 'for_assessment' => 'Assessment', 'for_payment' => 'Payment', 'enrolled' => 'Enrolled'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    post_guard();
    if ($stu['enrollment_status'] !== 'not_enrolled') {
        flash('error', 'You are already in the enrollment process.');
    } else {
        $pdo->beginTransaction();
        $next = (int)$pdo->query('SELECT COALESCE(MAX(queue_number), 0) + 1 FROM students FOR UPDATE')->fetchColumn();
        $pdo->prepare("UPDATE students SET enrollment_status = 'queued', queue_number = ? WHERE id = ? AND enrollment_status = 'not_enrolled'")
            ->execute([$next, $stu['id']]);
        $pdo->commit();
        audit_log('ENROLLMENT_QUEUE_JOINED', (int)$user['id'], $user['username'], 'student', (int)$stu['id'], "Queue no. {$next}");
        flash('success', "You are in the queue. Your number is {$next}.");
    }
    redirect_self();
}

$ahead = 0;
if ($stu['queue_number'] && $stu['enrollment_status'] === 'queued') {
    $a = $pdo->prepare("SELECT COUNT(*) FROM students WHERE enrollment_status = 'queued' AND queue_number < ?");
    $a->execute([$stu['queue_number']]);
    $ahead = (int)$a->fetchColumn();
}
$keys = array_keys(STEPS);
$idx  = array_search($stu['enrollment_status'], $keys, true);

render_header($user, 'Enrollment queue');
?>
<div class="card">
  <ol class="steps">
  <?php foreach ($keys as $i => $k): ?>
    <li class="<?= $i < $idx ? 'done' : ($i === $idx ? 'now' : '') ?>"><?= e(STEPS[$k]) ?></li>
  <?php endforeach; ?></ol>
</div>
<div class="card">
<?php if ($stu['enrollment_status'] === 'not_enrolled'): ?>
  <p>You have not joined the enrollment queue for <?= e(CURRENT_SY) ?>, <?= e(CURRENT_SEM) ?> semester.</p>
  <?= form_open() ?><button class="btn" type="submit">Join the queue</button></form>
<?php elseif ($stu['enrollment_status'] === 'queued'): ?>
  <p class="muted">Your queue number</p><p class="queue"><?= (int)$stu['queue_number'] ?></p>
  <p><?= $ahead === 0 ? 'You are next.' : $ahead . ' student' . ($ahead === 1 ? '' : 's') . ' ahead of you.' ?></p>
<?php elseif ($stu['enrollment_status'] === 'enrolled'): ?>
  <p class="msg success">You are enrolled for this term.</p>
<?php else: ?>
  <p>Current step: <strong><?= e(STEPS[$stu['enrollment_status']]) ?></strong>. Follow the instructions at that window.</p>
<?php endif; ?>
</div>
<?php render_footer();
