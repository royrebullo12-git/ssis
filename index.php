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
  <title>Student Services Information System</title>
  <link rel="stylesheet" href="<?= e(url('/assets/css/style.css')) ?>">
</head>
<body class="public">
<header class="public-top">
  <a class="brand-lockup" href="<?= e(url('/')) ?>"><span>SSIS</span><small>Student Services Information System</small></a>
  <a class="public-login" href="<?= e(url('/auth/login.php')) ?>">Student Portal Login</a>
</header>

<main>
  <section class="hero">
    <div class="hero-copy">
      <p class="eyebrow">UNIVERSITY STUDENT SERVICES</p>
      <h1>One portal for your student records and services.</h1>
      <p>Access enrollment status, grades, clearances, and document requests through a secure university service portal.</p>
      <div class="hero-actions">
        <a class="btn" href="<?= e(url('/auth/login.php')) ?>">Student Portal Login</a>
        <a class="btn alt" href="<?= e(url('/auth/login.php?portal=registrar')) ?>">Registrar Admissions</a>
      </div>
    </div>
    <div class="hero-panel">
      <div class="status-line"><span class="dot"></span><b>All core services operational</b></div>
      <div class="mini-stat"><strong>24/7</strong><span>Portal access</span></div>
      <div class="mini-stat"><strong>3+</strong><span>Student service offices</span></div>
      <div class="mini-stat"><strong>Secure</strong><span>Role-based access</span></div>
    </div>
  </section>

  <section class="public-grid">
    <article class="public-card">
      <p class="card-kicker">ANNOUNCEMENTS</p>
      <h2>Registrar services are available online</h2>
      <p>Students can monitor their service requests and clearance progress without visiting multiple offices.</p>
      <a href="<?= e(url('/auth/login.php')) ?>">Open student portal →</a>
    </article>
    <article class="public-card">
      <p class="card-kicker">OFFICE HOURS</p>
      <h2>Student Services</h2>
      <p>Monday–Friday<br>8:00 AM–5:00 PM</p>
      <small>Hours may vary during holidays and university activities.</small>
    </article>
    <article class="public-card">
      <p class="card-kicker">SYSTEM STATUS</p>
      <h2><span class="dot"></span> Operational</h2>
      <p>Authentication, student records, document requests, and staff services are available.</p>
    </article>
  </section>

  <section class="public-info">
    <div>
      <p class="card-kicker">QUICK HELP</p>
      <h2>Frequently asked questions</h2>
      <details><summary>How do I access my account?</summary><p>Use your student number and password on the Student Portal Login page.</p></details>
      <details><summary>I need to register a new student.</summary><p>Authorized Registrar or Admissions staff can use the Registrar Admissions portal to create and activate student records.</p></details>
      <details><summary>Who can help with a locked account?</summary><p>Contact the Admin office for account recovery and password reset assistance.</p></details>
    </div>
    <aside class="contact-card">
      <p class="card-kicker">CONTACT</p>
      <h2>Student Services Office</h2>
      <p>Registrar • Cashier • Department Services</p>
      <p><strong>Email:</strong> studentservices@ssis.edu.ph</p>
      <p><strong>Phone:</strong> (02) 8000-0000</p>
      <p><strong>Office:</strong> Student Services Building</p>
    </aside>
  </section>
</main>
<footer class="public-footer">© <?= date('Y') ?> Student Services Information System · Authorized university use only</footer>
</body>
</html>
