<?php
declare(strict_types=1);
define('SSIS_BOOT', true);
require_once __DIR__ . '/auth/auth_check.php';

$u = auth_user();
if ($u) {
    redirect(ROLE_HOME[$u['role']]);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Wilson University (WLS) | Student Portal</title>
  <link rel="icon" type="image/svg+xml" href="<?= e(url('/assets/wls-logo.svg')) ?>">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="<?= e(url('/assets/css/style.css')) ?>">
  <link rel="stylesheet" href="<?= e(url('/assets/css/wls.css?v=' . (string) filemtime(__DIR__ . '/assets/css/wls.css'))) ?>">
</head>
<body class="public">
<header class="public-top">
  <a class="brand-lockup" href="<?= e(url('/')) ?>" aria-label="Wilson University home">
    <img src="<?= e(url('/assets/wls-logo.svg')) ?>" alt="">
  </a>
  <a class="public-login" href="<?= e(url('/auth/login.php')) ?>">
    Portal Login <i class="bi bi-arrow-up-right" aria-hidden="true"></i>
  </a>
</header>

<main>
  <section class="hero">
    <div class="hero-copy">
      <p class="eyebrow"><span></span> WILSON UNIVERSITY <b>·</b> WLS</p>
      <h1>Find your place.<br><em>Build your future.</em></h1>
      <p>Connect with the Wilson University community and securely manage enrollment, grades, clearances, payments, and student services.</p>
      <div class="hero-actions">
        <a class="btn gold" href="<?= e(url('/auth/login.php')) ?>">Enter the WLS Portal <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
        <a class="btn alt" href="#university">Discover Wilson University</a>
      </div>
    </div>
    <div class="hero-panel">
      <p class="hero-panel-kicker">YOUR UNIVERSITY, CONNECTED</p>
      <div class="status-line"><span class="dot"></span><b>Welcome to Wilson University</b></div>
      <div class="mini-stat"><span class="mini-stat-icon"><i class="bi bi-mortarboard" aria-hidden="true"></i></span><span><strong>Learning</strong><small>Academic life and progress</small></span></div>
      <div class="mini-stat"><span class="mini-stat-icon"><i class="bi bi-people" aria-hidden="true"></i></span><span><strong>Community</strong><small>One connected campus</small></span></div>
      <div class="mini-stat"><span class="mini-stat-icon"><i class="bi bi-shield-lock" aria-hidden="true"></i></span><span><strong>Student services</strong><small>Secure access to your portal</small></span></div>
      <a class="hero-panel-link" href="<?= e(url('/auth/login.php')) ?>">Continue to your portal <i class="bi bi-arrow-up-right" aria-hidden="true"></i></a>
    </div>
    <a class="hero-scroll-link" href="#university"><span>SCROLL TO EXPLORE</span><i class="bi bi-arrow-down" aria-hidden="true"></i></a>
  </section>

  <section class="public-grid" aria-label="University information">
    <article class="public-card">
      <span class="public-card-icon"><i class="bi bi-megaphone" aria-hidden="true"></i></span>
      <p class="card-kicker">ANNOUNCEMENT</p>
      <h2>Welcome to Wilson University</h2>
      <p>Visit the WLS Portal to check your academic records, enrollment, and student service requests.</p>
      <a href="<?= e(url('/auth/login.php')) ?>">Sign in to your portal →</a>
    </article>
    <article class="public-card">
      <span class="public-card-icon"><i class="bi bi-grid" aria-hidden="true"></i></span>
      <p class="card-kicker">QUICK LINKS</p>
      <h2>University portals</h2>
      <p><a href="<?= e(url('/auth/login.php')) ?>">Student and staff login</a></p>
      <p><a href="<?= e(url('/auth/login.php?portal=registrar')) ?>">Registrar admissions</a></p>
    </article>
    <article class="public-card">
      <span class="public-card-icon"><i class="bi bi-book-half" aria-hidden="true"></i></span>
      <p class="card-kicker">STUDENT SERVICES</p>
      <h2>Support throughout your studies</h2>
      <p>Find enrollment information, approved grades, official documents, and campus service updates in one secure portal.</p>
    </article>
  </section>

  <section class="public-info" id="university">
    <div>
      <p class="card-kicker">QUICK HELP</p>
      <h2>About Wilson University</h2>
      <p>Wilson University (WLS) brings academic programs and student support together to help every learner move forward.</p>
      <details><summary>How do I access my account?</summary><p>Use your student number and password on the Student Portal Login page.</p></details>
      <details><summary>I need to register a new student.</summary><p>Authorized Registrar or Admissions staff can use the Registrar Admissions portal to create and activate student records.</p></details>
      <details><summary>Who can help with a locked account?</summary><p>Use Forgot Password to verify your registered email or mobile number, or contact the Registrar.</p></details>
    </div>
    <aside class="contact-card">
      <span class="contact-icon"><i class="bi bi-chat-square-text" aria-hidden="true"></i></span>
      <p class="card-kicker">CONTACT</p>
      <h2>Student Services</h2>
      <p>Registrar · Cashier · Department Services</p>
      <p>For account, enrollment, or document support, contact your designated Wilson University office.</p>
    </aside>
  </section>
</main>
<footer class="public-footer">© <?= date('Y') ?> Wilson University (WLS) · Authorized university use only</footer>
</body>
</html>
