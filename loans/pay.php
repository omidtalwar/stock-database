<?php
require_once '../includes/session.php';
requireLogin();
require_once '../includes/lang.php';
require_once '../config/db.php';
require_once '../includes/currency.php';

$id   = (int)($_GET['id'] ?? 0);
$loan = $pdo->prepare("SELECT * FROM loans WHERE id = ? AND status != 'paid'");
$loan->execute([$id]);
$loan = $loan->fetch();

if (!$loan) { header('Location: index.php'); exit; }

// Auto-migrate
try { $pdo->exec("ALTER TABLE loan_payments ADD COLUMN bill_file VARCHAR(255) NULL AFTER notes"); } catch (\PDOException $e) {}
try { $pdo->exec("ALTER TABLE loan_payments ADD COLUMN hawala_number VARCHAR(100) NULL AFTER bill_file"); } catch (\PDOException $e) {}

$remaining = (float)$loan['amount'] - (float)$loan['paid'];
$pageTitle = 'Record Payment';
$errors    = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $amount       = (float)($_POST['amount'] ?? 0);
    $notes        = trim($_POST['notes']         ?? '');
    $pdate        = trim($_POST['payment_date']  ?? '') ?: null;
    $hawalaNo     = trim($_POST['hawala_number'] ?? '');
    $billFile     = null;

    if ($amount <= 0)         $errors[] = 'Payment amount must be greater than zero.';
    if ($amount > $remaining) $errors[] = 'Payment exceeds remaining balance ($ ' . number_format($remaining, 2) . ').';

    // Handle file upload
    if (!empty($_FILES['bill']['name'])) {
        $file     = $_FILES['bill'];
        $allowed  = ['image/jpeg','image/png','image/webp','image/gif','application/pdf'];
        $maxBytes = 8 * 1024 * 1024;

        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'File upload failed (error code ' . $file['error'] . ').';
        } elseif (!in_array($file['type'], $allowed)) {
            $errors[] = 'Only JPG, PNG, WebP, GIF, or PDF files are allowed.';
        } elseif ($file['size'] > $maxBytes) {
            $errors[] = 'File is too large. Maximum size is 8 MB.';
        } else {
            $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $fname = 'bill_' . $loan['id'] . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            $dest  = __DIR__ . '/../uploads/loan-bills/' . $fname;
            if (!move_uploaded_file($file['tmp_name'], $dest)) {
                $errors[] = 'Could not save the uploaded file. Check folder permissions.';
            } else {
                $billFile = $fname;
            }
        }
    }

    if (!$errors) {
        $pdo->prepare("
            INSERT INTO loan_payments (loan_id, amount, currency, payment_date, notes, bill_file, hawala_number, created_by)
            VALUES (?, ?, 'USD', ?, ?, ?, ?, ?)
        ")->execute([$loan['id'], $amount, $pdate, $notes, $billFile, $hawalaNo ?: null, $_SESSION['user_id']]);

        $newPaid   = (float)$loan['paid'] + $amount;
        $newStatus = $newPaid >= (float)$loan['amount'] ? 'paid' : $loan['status'];
        $pdo->prepare("UPDATE loans SET paid = ?, status = ? WHERE id = ?")->execute([$newPaid, $newStatus, $loan['id']]);

        $_SESSION['success'] = 'Payment of $' . number_format($amount, 2) . ' recorded.';
        header('Location: view.php?id=' . $loan['id']);
        exit;
    }
}

require_once '../includes/header.php';
?>

<style>
#bill-zone {
    border: 2px dashed rgba(0,103,192,0.25);
    border-radius: 12px;
    padding: 26px 20px;
    text-align: center;
    cursor: pointer;
    transition: border-color .2s, background .2s;
    background: rgba(0,103,192,0.02);
    position: relative;
}
#bill-zone:hover, #bill-zone.drag-over {
    border-color: rgba(0,103,192,0.55);
    background: rgba(0,103,192,0.05);
}
#bill-zone input[type="file"] {
    position: absolute; inset: 0; width: 100%; height: 100%;
    opacity: 0; cursor: pointer;
}
#bill-preview-wrap { display: none; margin-top: 14px; }
#bill-preview-img  { max-width: 100%; max-height: 220px; border-radius: 8px; object-fit: contain; border: 1px solid rgba(0,0,0,0.08); }
#bill-preview-pdf  {
    display: flex; align-items: center; gap: 12px;
    padding: 12px 16px; border-radius: 10px;
    background: rgba(196,43,28,0.06); border: 1px solid rgba(196,43,28,0.18);
    font-size: .85rem; font-weight: 600; color: #C42B1C;
}
#bill-clear {
    display: none; margin-top: 10px;
    font-size: .78rem; color: #888; border: none; background: none;
    cursor: pointer; text-decoration: underline;
}
#bill-clear:hover { color: #C42B1C; }
.usd-badge {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 6px 14px; border-radius: 8px;
    background: rgba(16,124,16,0.08); border: 1px solid rgba(16,124,16,0.2);
    color: #107C10; font-weight: 700; font-size: .88rem;
}
</style>

<div class="page-header">
    <a href="view.php?id=<?= $loan['id'] ?>" class="text-muted small"><i class="bi bi-arrow-left me-1"></i>Back to Loan</a>
    <h4 class="mt-1 mb-0"><i class="bi bi-cash-stack me-2 text-success"></i>Record Payment</h4>
    <p class="text-muted small mb-0">For: <strong><?= htmlspecialchars($loan['borrower']) ?></strong></p>
</div>

<?php foreach ($errors as $e): ?>
<div class="alert alert-danger"><i class="bi bi-exclamation-circle me-2"></i><?= htmlspecialchars($e) ?></div>
<?php endforeach; ?>

<!-- Loan summary -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card p-3 text-center">
            <div class="text-muted" style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.4px;">Total Loan</div>
            <div class="fw-bold mt-1">$ <?= number_format($loan['amount'], 2) ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card p-3 text-center">
            <div class="text-muted" style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.4px;">Already Paid</div>
            <div class="fw-bold text-success mt-1">$ <?= number_format($loan['paid'], 2) ?></div>
        </div>
    </div>
    <div class="col-12 col-md-6">
        <div class="card p-3 text-center" style="border-color:rgba(248,113,113,0.3);background:rgba(248,113,113,0.04);">
            <div class="text-muted" style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.4px;">Remaining Balance</div>
            <div class="fw-bold text-danger mt-1" style="font-size:1.3rem;">$ <?= number_format($remaining, 2) ?></div>
        </div>
    </div>
</div>

<div class="card" style="max-width:580px;">
    <div class="card-body p-4">
        <form method="POST" enctype="multipart/form-data">
            <div class="row g-3">

                <!-- Amount (USD only) -->
                <div class="col-8">
                    <label class="form-label fw-semibold">Payment Amount <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <span class="input-group-text fw-bold text-success">$</span>
                        <input type="number" name="amount" class="form-control" step="0.01" min="0.01"
                               max="<?= $remaining ?>"
                               value="<?= htmlspecialchars($_POST['amount'] ?? '') ?>"
                               placeholder="0.00" required autofocus>
                    </div>
                    <div class="form-text">Max: $ <?= number_format($remaining, 2) ?></div>
                </div>
                <div class="col-4 d-flex flex-column justify-content-center">
                    <label class="form-label fw-semibold">Currency</label>
                    <span class="usd-badge"><i class="bi bi-currency-dollar"></i> USD only</span>
                </div>

                <!-- Hawala Number -->
                <div class="col-12">
                    <label class="form-label fw-semibold">
                        <i class="bi bi-hash me-1" style="color:#F59E0B;"></i>Hawala Number
                        <span class="text-muted fw-normal" style="font-size:.75rem;">(optional)</span>
                    </label>
                    <div class="input-group">
                        <span class="input-group-text" style="background:rgba(245,158,11,0.08);border-color:rgba(245,158,11,0.3);color:#D97706;">
                            <i class="bi bi-hash"></i>
                        </span>
                        <input type="text" name="hawala_number" class="form-control"
                               style="border-color:rgba(245,158,11,0.3);"
                               value="<?= htmlspecialchars($_POST['hawala_number'] ?? '') ?>"
                               placeholder="e.g. HW-2024-00123">
                    </div>
                    <div class="form-text">Hawala transaction reference number for this payment</div>
                </div>

                <!-- Date -->
                <div class="col-12">
                    <label class="form-label fw-semibold">Payment Date</label>
                    <input type="date" name="payment_date" class="form-control"
                           value="<?= htmlspecialchars($_POST['payment_date'] ?? date('Y-m-d')) ?>">
                </div>

                <!-- Notes -->
                <div class="col-12">
                    <label class="form-label fw-semibold">Notes</label>
                    <textarea name="notes" class="form-control" rows="2"
                              placeholder="Optional payment notes…"><?= htmlspecialchars($_POST['notes'] ?? '') ?></textarea>
                </div>

                <!-- Bill uploader -->
                <div class="col-12">
                    <label class="form-label fw-semibold">
                        <i class="bi bi-paperclip me-1"></i>Attach Bill / Receipt
                        <span class="text-muted fw-normal" style="font-size:.75rem;">(optional)</span>
                    </label>
                    <div id="bill-zone">
                        <input type="file" name="bill" id="bill-input"
                               accept="image/jpeg,image/png,image/webp,image/gif,application/pdf">
                        <div id="bill-placeholder">
                            <i class="bi bi-cloud-arrow-up" style="font-size:2rem;color:rgba(0,103,192,0.4);display:block;margin-bottom:8px;"></i>
                            <div style="font-weight:600;font-size:.88rem;color:#444;">Drop file here or click to browse</div>
                            <div style="font-size:.75rem;color:#888;margin-top:4px;">JPG · PNG · WebP · PDF &nbsp;·&nbsp; Max 8 MB</div>
                        </div>
                    </div>
                    <div id="bill-preview-wrap">
                        <img id="bill-preview-img" src="" alt="Bill preview" style="display:none;">
                        <div id="bill-preview-pdf" style="display:none;">
                            <i class="bi bi-file-earmark-pdf-fill" style="font-size:1.6rem;"></i>
                            <div>
                                <div id="bill-pdf-name" style="font-weight:700;"></div>
                                <div id="bill-pdf-size" style="font-size:.72rem;font-weight:400;color:#888;margin-top:2px;"></div>
                            </div>
                        </div>
                        <button type="button" id="bill-clear">&#x2715; Remove file</button>
                    </div>
                </div>

                <!-- Submit -->
                <div class="col-12 d-flex gap-2 pt-2">
                    <button type="submit" class="btn btn-success px-4">
                        <i class="bi bi-check-circle me-2"></i>Save Payment
                    </button>
                    <a href="view.php?id=<?= $loan['id'] ?>" class="btn btn-light px-4">Cancel</a>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    const zone     = document.getElementById('bill-zone');
    const input    = document.getElementById('bill-input');
    const ph       = document.getElementById('bill-placeholder');
    const prevWrap = document.getElementById('bill-preview-wrap');
    const prevImg  = document.getElementById('bill-preview-img');
    const prevPdf  = document.getElementById('bill-preview-pdf');
    const pdfName  = document.getElementById('bill-pdf-name');
    const pdfSize  = document.getElementById('bill-pdf-size');
    const clearBtn = document.getElementById('bill-clear');

    function fmtSize(b) { return b < 1048576 ? (b/1024).toFixed(0)+' KB' : (b/1048576).toFixed(1)+' MB'; }

    function showFile(file) {
        ph.style.display       = 'none';
        prevWrap.style.display = 'block';
        clearBtn.style.display = 'inline-block';
        if (file.type.startsWith('image/')) {
            prevPdf.style.display = 'none';
            prevImg.style.display = 'block';
            prevImg.src = URL.createObjectURL(file);
        } else {
            prevImg.style.display = 'none';
            prevPdf.style.display = 'flex';
            pdfName.textContent   = file.name;
            pdfSize.textContent   = fmtSize(file.size);
        }
    }

    function clearFile() {
        input.value = '';
        ph.style.display = '';
        prevWrap.style.display = prevImg.style.display = prevPdf.style.display = 'none';
        clearBtn.style.display = 'none';
        if (prevImg.src) URL.revokeObjectURL(prevImg.src);
        prevImg.src = '';
    }

    input.addEventListener('change', () => { if (input.files[0]) showFile(input.files[0]); });
    clearBtn.addEventListener('click', clearFile);
    zone.addEventListener('dragover',  e => { e.preventDefault(); zone.classList.add('drag-over'); });
    zone.addEventListener('dragleave', () => zone.classList.remove('drag-over'));
    zone.addEventListener('drop', e => {
        e.preventDefault(); zone.classList.remove('drag-over');
        const f = e.dataTransfer.files[0]; if (!f) return;
        try { const dt = new DataTransfer(); dt.items.add(f); input.files = dt.files; } catch(_) {}
        showFile(f);
    });
})();
</script>

<?php require_once '../includes/footer.php'; ?>
