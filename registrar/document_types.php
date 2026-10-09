<?php
declare(strict_types=1);
define('SSIS_BOOT', true);
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../includes/layout.php';
$user = require_role('registrar');
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    post_guard();
    $action = post_str('action', 12);
    $name = post_str('name', 100);
    $feeRaw = post_str('fee', 12);
    $feeValid = preg_match('/^\d{1,6}(?:\.\d{1,2})?$/', $feeRaw) === 1;
    $fee = $feeValid ? (float)$feeRaw : -1;
    $days = post_int('processing_days');
    $office = post_str('issuing_office', 120);
    $departmentRaw = post_str('issuing_department_id', 10);
    $departmentValue = $departmentRaw === '' ? null : filter_var($departmentRaw, FILTER_VALIDATE_INT);
    $departmentId = $departmentValue === null || $departmentValue === false ? null : (int)$departmentValue;
    $departmentValid = ($departmentRaw === '' || ($departmentValue !== false && $departmentId > 0));
    if ($name === '' || $office === '' || !$feeValid || $fee < 0 || $fee > 100000 || $days < 1 || $days > 120 || !$departmentValid) {
        flash('error', 'Enter a document name, issuing office, non-negative fee, and processing time between 1 and 120 days.');
    } elseif ($departmentId !== null && !in_array((string)$departmentId, array_map(
        static fn(array $department): string => (string)$department['id'],
        $pdo->query('SELECT id FROM departments')->fetchAll()
    ), true)) {
        flash('error', 'Choose a department that exists in the university directory.');
    } else {
        try {
            if ($action === 'create') {
                $pdo->prepare(
                    'INSERT INTO document_types (name, fee, processing_days, issuing_office, issuing_department_id)
                     VALUES (?, ?, ?, ?, ?)'
                )->execute([$name, $fee, $days, $office, $departmentId]);
                flash('success', 'Document type created.');
            } elseif ($action === 'save') {
                $id = post_int('id');
                if ($id < 1) throw new InvalidArgumentException('Invalid document type id.');
                $statement = $pdo->prepare(
                    'UPDATE document_types SET name = ?, fee = ?, processing_days = ?, issuing_office = ?, issuing_department_id = ? WHERE id = ?'
                );
                $statement->execute([$name, $fee, $days, $office, $departmentId, $id]);
                flash('success', 'Document type and issuing route saved.');
            } else {
                flash('error', 'Unknown document-type action.');
            }
        } catch (InvalidArgumentException $e) {
            flash('error', $e->getMessage());
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') flash('error', 'That document name already exists or the department is invalid.');
            else { error_log('[WLS] document type update failed: ' . $e->getMessage()); flash('error', 'The document type could not be saved.'); }
        }
    }
    redirect_self();
}

$departments = $pdo->query('SELECT id, code, name FROM departments ORDER BY name')->fetchAll();
$types = $pdo->query(
    'SELECT t.*, d.code AS department_code FROM document_types t
      LEFT JOIN departments d ON d.id = t.issuing_department_id ORDER BY t.name'
)->fetchAll();
render_header($user, 'Document types and routing');
?>
<section class="card">
  <p class="card-kicker">DOCUMENT CATALOG</p><h2>Add a document type</h2>
  <p class="muted">Choose the issuing office and, for department-issued documents, the department account that should receive requests.</p>
  <?= form_open('create') ?>
    <div class="form-grid form-grid-3">
      <div><label for="name">Document name</label><input id="name" name="name" maxlength="100" required></div>
      <div><label for="fee">Fee per copy (₱)</label><input id="fee" name="fee" type="number" min="0" max="100000" step="0.01" value="0" required></div>
      <div><label for="processing_days">Processing time (days)</label><input id="processing_days" name="processing_days" type="number" min="1" max="120" value="3" required></div>
      <div><label for="issuing_office">Issuing office label</label><input id="issuing_office" name="issuing_office" maxlength="120" value="Registrar" required></div>
      <div><label for="issuing_department_id">Route to department</label><select id="issuing_department_id" name="issuing_department_id"><option value="">Registrar office</option>
        <?php foreach ($departments as $department): ?><option value="<?= (int)$department['id'] ?>"><?= e($department['code'] . ' · ' . $department['name']) ?></option><?php endforeach; ?>
      </select><small class="field-help">Ask an Admin to add a missing office (for example, Guidance) to the Department directory before routing requests to it.</small></div>
    </div>
    <p class="form-actions"><button class="btn" type="submit">Add document type</button></p>
  </form>
</section>
<section class="card">
  <h2>Configured routes</h2>
  <?php if (!$types): ?><div class="empty">No document types have been configured yet.</div><?php else: ?>
  <div class="tablewrap table-responsive"><table><thead><tr><th>Document</th><th>Fee</th><th>Processing days</th><th>Issuing office</th><th>Routing</th><th>Update</th></tr></thead><tbody>
  <?php foreach ($types as $type): $formId = 'document-type-' . (int)$type['id']; ?><tr><td>
    <form id="<?= e($formId) ?>" method="post" action=""><?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)$type['id'] ?>"></form>
    <label class="sr-only" for="name-<?= (int)$type['id'] ?>">Document name</label><input form="<?= e($formId) ?>" id="name-<?= (int)$type['id'] ?>" name="name" maxlength="100" value="<?= e($type['name']) ?>" required></td>
    <td><input form="<?= e($formId) ?>" type="number" name="fee" min="0" max="100000" step="0.01" value="<?= e((string)$type['fee']) ?>" aria-label="Fee per copy" required></td>
    <td><input form="<?= e($formId) ?>" type="number" name="processing_days" min="1" max="120" value="<?= (int)$type['processing_days'] ?>" aria-label="Processing days" required></td>
    <td><input form="<?= e($formId) ?>" type="text" name="issuing_office" maxlength="120" value="<?= e($type['issuing_office']) ?>" aria-label="Issuing office" required></td>
    <td><select form="<?= e($formId) ?>" name="issuing_department_id" aria-label="Department route"><option value="">Registrar</option>
      <?php foreach ($departments as $department): ?><option value="<?= (int)$department['id'] ?>"<?= (int)$department['id'] === (int)$type['issuing_department_id'] ? ' selected' : '' ?>><?= e($department['code'] . ' · ' . $department['name']) ?></option><?php endforeach; ?>
    </select><?= $type['department_code'] ? '<small class="field-help">Department ' . e($type['department_code']) . '</small>' : '' ?></td>
    <td><button form="<?= e($formId) ?>" class="btn alt sm" type="submit">Save route</button></td>
  </tr><?php endforeach; ?>
  </tbody></table></div><?php endif; ?>
</section>
<?php render_footer(); ?>
