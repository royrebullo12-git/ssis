<?php
declare(strict_types=1);
define('SSIS_BOOT', true);
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/../includes/layout.php';
$user = require_login();

$statement = db()->prepare(
    'SELECT title, body, link, created_at, read_at
       FROM notifications
      WHERE user_id = ?
      ORDER BY created_at DESC
      LIMIT 100'
);
$statement->execute([(int)$user['id']]);
$notifications = $statement->fetchAll();
$unreadStatement = db()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL');
$unreadStatement->execute([(int)$user['id']]);
$unreadCount = (int)$unreadStatement->fetchColumn();

render_header($user, 'Notifications');
?>
<section class="card notification-center">
  <div class="section-title">
    <div><p class="card-kicker">ACCOUNT UPDATES</p><h2>Notifications</h2></div>
    <?php if ($unreadCount > 0): ?>
      <form method="post" action="<?= e(url('/auth/notifications.php')) ?>">
        <?= csrf_field() ?>
        <button class="btn alt" type="submit">Mark all as read</button>
      </form>
    <?php endif; ?>
  </div>
  <?= notification_items_markup($notifications) ?>
</section>
<?php render_footer(); ?>
