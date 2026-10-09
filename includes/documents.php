<?php
declare(strict_types=1);
if (!defined('SSIS_BOOT')) { http_response_code(403); exit('Direct access forbidden.'); }
require_once __DIR__ . '/notifications.php';

/**
 * Document request workflow
 *   submitted --accept--> awaiting_payment (fee > 0) | processing (free)
 *   awaiting_payment --(Cashier records full payment)--> processing
 *   processing --> ready --> released
 *   submitted | awaiting_payment | processing --reject--> rejected
 *   student may cancel while submitted | awaiting_payment (unpaid)
 */
const DOC_ACTIONS = [
    'submitted'        => ['accept', 'reject'],
    'awaiting_payment' => ['reject'],
    'processing'       => ['ready', 'reject'],
    'ready'            => ['released'],
];

function doc_types(): array
{
    return db()->query('SELECT id, name, fee, processing_days FROM document_types WHERE is_active = 1 ORDER BY name')->fetchAll();
}

function doc_create(int $studentId, int $typeId, string $purpose, int $copies): ?string
{
    if ($copies < 1 || $copies > 10) return 'Copies must be between 1 and 10.';
    if (mb_strlen($purpose) < 5) return 'Please state the purpose (at least 5 characters).';

    $pdo = db();
    $t = $pdo->prepare('SELECT id, fee, issuing_department_id, issuing_office FROM document_types WHERE id = ? AND is_active = 1');
    $t->execute([$typeId]);
    $type = $t->fetch();
    if (!$type) return 'Choose a valid document type.';

    $fee = round((float)$type['fee'] * $copies, 2);   // fee always computed server-side
    $ins = $pdo->prepare(
        'INSERT INTO document_requests (student_id, document_type_id, purpose, copies, fee_amount, routed_department_id, status)
         VALUES (?, ?, ?, ?, ?, ?, "submitted")'
    );
    $departmentId = $type['issuing_department_id'] !== null ? (int)$type['issuing_department_id'] : null;
    $ins->execute([$studentId, $typeId, $purpose, $copies, $fee, $departmentId]);
    $office = (string)$type['issuing_office'];
    if ($departmentId !== null) {
        notify_role('department', 'New document request', 'A student document request is waiting for ' . $office . '.', '/department/document_requests.php', $departmentId);
    } else {
        notify_role('registrar', 'New document request', 'A student document request is waiting for Registrar review.', '/registrar/requests.php');
    }
    audit_log('DOC_REQUEST_CREATED', $_SESSION['uid'] ?? null, $_SESSION['username'] ?? null,
              'document_request', (int)$pdo->lastInsertId(), "Fee {$fee}");
    return null;
}

function doc_cancel(int $studentId, int $requestId): ?string
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare(
            'SELECT r.id, r.status, r.payment_id, p.amount_paid FROM document_requests r
               LEFT JOIN payments p ON p.id = r.payment_id
              WHERE r.id = ? AND r.student_id = ? FOR UPDATE'
        );
        $st->execute([$requestId, $studentId]);          // ownership enforced in the query
        $r = $st->fetch();
        if (!$r) { $pdo->rollBack(); return 'Request not found.'; }
        if (!in_array($r['status'], ['submitted', 'awaiting_payment'], true) || (float)($r['amount_paid'] ?? 0) > 0) {
            $pdo->rollBack();
            return 'This request can no longer be cancelled. Ask the Registrar.';
        }
        if ($r['payment_id']) {
            $pdo->prepare("UPDATE payments SET status = 'void' WHERE id = ?")->execute([(int)$r['payment_id']]);
        }
        $pdo->prepare("UPDATE document_requests SET status = 'cancelled' WHERE id = ?")->execute([$requestId]);
        $pdo->commit();
        audit_log('DOC_REQUEST_CANCELLED', $_SESSION['uid'] ?? null, $_SESSION['username'] ?? null, 'document_request', $requestId);
        return null;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[SSIS] doc_cancel: ' . $e->getMessage());
        return 'Could not cancel the request.';
    }
}

/** Registrar-side transition. Returns an error string or null. */
function doc_transition(int $requestId, string $action, string $remarks, int $userId, ?int $departmentId = null): ?string
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $routing = $departmentId === null ? 'r.routed_department_id IS NULL' : 'r.routed_department_id = ?';
        $params = $departmentId === null ? [$requestId] : [$requestId, $departmentId];
        $st = $pdo->prepare(
            'SELECT r.*, t.name AS type_name, p.amount_paid, s.user_id AS student_user_id FROM document_requests r
               JOIN document_types t ON t.id = r.document_type_id
               JOIN students s ON s.id = r.student_id
               LEFT JOIN payments p ON p.id = r.payment_id
              WHERE r.id = ? AND ' . $routing . ' FOR UPDATE'
        );
        $st->execute($params);
        $r = $st->fetch();
        if (!$r) { $pdo->rollBack(); return 'Request not found.'; }
        if (!in_array($action, DOC_ACTIONS[$r['status']] ?? [], true)) {
            $pdo->rollBack();
            return 'That action is not allowed while the request is "' . label($r['status']) . '".';
        }
        if ($action === 'reject' && $remarks === '') { $pdo->rollBack(); return 'Enter a reason for rejecting.'; }

        $note = $remarks !== '' ? $remarks : $r['remarks'];
        $newStatus = null;

        if ($action === 'accept') {
            if ((float)$r['fee_amount'] > 0) {
                $newStatus = 'awaiting_payment';
                $pdo->prepare(
                    'INSERT INTO payments (student_id, reference_no, description, amount_due, status)
                     VALUES (?, ?, ?, ?, "unpaid")'
                )->execute([(int)$r['student_id'], new_reference('DOC'),
                            'Document request #' . $requestId . ' - ' . $r['type_name'], $r['fee_amount']]);
                $pid = (int)$pdo->lastInsertId();
                $pdo->prepare('UPDATE document_requests SET status="awaiting_payment", payment_id=?, remarks=?, processed_by=? WHERE id=?')
                    ->execute([$pid, $note, $userId, $requestId]);
            } else {
                $newStatus = 'processing';
                $pdo->prepare('UPDATE document_requests SET status="processing", remarks=?, processed_by=? WHERE id=?')
                    ->execute([$note, $userId, $requestId]);
            }
        } else {
            $new = ['reject' => 'rejected', 'ready' => 'ready', 'released' => 'released'][$action];
            $newStatus = $new;
            if ($action === 'reject' && $r['payment_id'] && (float)($r['amount_paid'] ?? 0) == 0.0) {
                $pdo->prepare("UPDATE payments SET status = 'void' WHERE id = ?")->execute([(int)$r['payment_id']]);
            }
            $pdo->prepare('UPDATE document_requests SET status=?, remarks=?, processed_by=? WHERE id=?')
                ->execute([$new, $note, $userId, $requestId]);
        }
        create_notification((int)$r['student_user_id'], 'Document request updated', 'Your ' . $r['type_name'] . ' request is now ' . label((string)$newStatus) . '.', '/student/requests.php');
        $pdo->commit();
        audit_log('DOC_REQUEST_' . strtoupper($action), $userId, $_SESSION['username'] ?? null, 'document_request', $requestId, $remarks);
        return null;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[SSIS] doc_transition: ' . $e->getMessage());
        return 'Could not update the request.';
    }
}

/** Called by the Cashier after a payment: a fully paid awaiting_payment request moves to processing. */
function doc_after_payment(PDO $pdo, int $paymentId): void
{
    $pdo->prepare(
        "UPDATE document_requests r JOIN payments p ON p.id = r.payment_id
            SET r.status = 'processing'
          WHERE r.payment_id = ? AND r.status = 'awaiting_payment' AND p.status = 'paid'"
    )->execute([$paymentId]);
    $recipients = $pdo->prepare(
        "SELECT s.user_id, t.name FROM document_requests r
           JOIN students s ON s.id = r.student_id JOIN document_types t ON t.id = r.document_type_id
          WHERE r.payment_id = ? AND r.status = 'processing'"
    );
    $recipients->execute([$paymentId]);
    foreach ($recipients->fetchAll() as $recipient) {
        create_notification((int)$recipient['user_id'], 'Document request in progress', 'Your ' . $recipient['name'] . ' request is now being processed.', '/student/requests.php');
    }
}
