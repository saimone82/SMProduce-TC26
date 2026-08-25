<?php
/**
 * Empty Bin Receiving - PDF report + Windows printing helpers.
 */

if (!defined('EBR_NO_PRINTER_ADMIN_HASH')) {
    // Password hash for the admin override. Plaintext password is never stored in PHP source.
    define('EBR_NO_PRINTER_ADMIN_HASH', '$2y$12$u2yx2io8ituaYf7txV38y.Y6M1WfjBHqrvg3.94vIEkyIf0.jWVW6');
}

if (!function_exists('ebr_verify_no_printer_admin_password')) {
    function ebr_verify_no_printer_admin_password(string $password): bool
    {
        return $password !== '' && password_verify($password, EBR_NO_PRINTER_ADMIN_HASH);
    }
}


if (!function_exists('ebr_ps_quote')) {
    function ebr_ps_quote(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }
}

if (!function_exists('ebr_windows_printers')) {
    function ebr_windows_printers(): array
    {
        if (stripos(PHP_OS_FAMILY, 'Windows') === false) {
            return [];
        }

        $cmd = 'powershell -NoProfile -ExecutionPolicy Bypass -Command "Get-Printer | Select-Object -ExpandProperty Name | Out-String -Width 4096"';
        $out = @shell_exec($cmd);
        if (!is_string($out) || trim($out) === '') {
            return [];
        }

        $rows = preg_split('/\R/u', trim($out)) ?: [];
        $rows = array_values(array_unique(array_filter(array_map('trim', $rows), static fn($v) => $v !== '')));
        natcasesort($rows);
        return array_values($rows);
    }
}

if (!function_exists('ebr_ensure_print_settings')) {
    function ebr_ensure_print_settings(mysqli $mysqli): void
    {
        $mysqli->query("
            CREATE TABLE IF NOT EXISTS empty_bin_report_settings (
                id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
                windows_printer VARCHAR(255) NULL,
                updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $mysqli->query("INSERT IGNORE INTO empty_bin_report_settings(id, windows_printer) VALUES(1, NULL)");
    }
}

if (!function_exists('ebr_get_report_printer')) {
    function ebr_get_report_printer(mysqli $mysqli): string
    {
        ebr_ensure_print_settings($mysqli);
        $res = $mysqli->query("SELECT windows_printer FROM empty_bin_report_settings WHERE id=1 LIMIT 1");
        if ($res && ($row = $res->fetch_assoc())) {
            return trim((string)($row['windows_printer'] ?? ''));
        }
        return '';
    }
}

if (!function_exists('ebr_set_report_printer')) {
    function ebr_set_report_printer(mysqli $mysqli, string $printer): bool
    {
        ebr_ensure_print_settings($mysqli);
        $stmt = $mysqli->prepare("
            INSERT INTO empty_bin_report_settings(id, windows_printer)
            VALUES(1, ?)
            ON DUPLICATE KEY UPDATE windows_printer=VALUES(windows_printer), updated_at=CURRENT_TIMESTAMP
        ");
        if (!$stmt) return false;
        $value = $printer !== '' ? $printer : null;
        $stmt->bind_param('s', $value);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }
}


if (!function_exists('ebr_report_file_for_record')) {
    function ebr_report_file_for_record(int $id, string $date): array
    {
        $safeDate = preg_replace('/[^0-9\-]/', '', $date);
        $filename = sprintf('empty_bins_%s_%06d.pdf', $safeDate ?: date('Y-m-d'), max(0, $id));
        $absolute = __DIR__ . '/../data/empty_bin_reports/' . $filename;

        return [
            'filename' => $filename,
            'path' => $absolute,
            'exists' => is_file($absolute) && filesize($absolute) > 100,
            'url' => '../data/empty_bin_reports/' . rawurlencode($filename),
        ];
    }
}

if (!function_exists('ebr_generate_receipt_pdf')) {
    function ebr_generate_receipt_pdf(array $row, array $summary = []): array
    {
        $fpdf = __DIR__ . '/../vendor/setasign/fpdf/fpdf.php';
        if (!is_file($fpdf)) {
            return ['ok'=>false, 'error'=>'FPDF library not found.'];
        }
        require_once $fpdf;

        $outDir = __DIR__ . '/../data/empty_bin_reports';
        if (!is_dir($outDir) && !@mkdir($outDir, 0775, true) && !is_dir($outDir)) {
            return ['ok'=>false, 'error'=>'Unable to create empty_bin_reports directory.'];
        }

        $id = (int)($row['id'] ?? 0);
        $safeDate = preg_replace('/[^0-9\-]/', '', (string)($row['date'] ?? date('Y-m-d')));
        $filename = sprintf('empty_bins_%s_%06d.pdf', $safeDate ?: date('Y-m-d'), max(0, $id));
        $absolute = $outDir . DIRECTORY_SEPARATOR . $filename;

        try {
            $pdf = new FPDF('P', 'mm', 'Letter');
            $pdf->SetTitle('Empty Bin Receiving #' . $id);
            $pdf->SetAuthor('SM Produce LTD');
            $pdf->SetMargins(15, 14, 15);
            $pdf->SetAutoPageBreak(true, 15);
            $pdf->AddPage();

            $logo = __DIR__ . '/../logo/logo.png';
            if (is_file($logo)) {
                $pdf->Image($logo, 15, 12, 28);
            }

            $pdf->SetXY(48, 14);
            $pdf->SetFont('Arial', 'B', 17);
            $pdf->Cell(0, 8, 'EMPTY BIN RECEIVING REPORT', 0, 1, 'L');
            $pdf->SetX(48);
            $pdf->SetFont('Arial', '', 9);
            $pdf->Cell(0, 5, 'SM Produce LTD', 0, 1, 'L');
            $pdf->SetX(48);
            $pdf->Cell(0, 5, 'Receipt #' . $id, 0, 1, 'L');

            $pdf->Ln(10);

            $createdAt = (string)($row['created_at'] ?? date('Y-m-d H:i:s'));
            $enteredBy = trim((string)($row['entered_by'] ?? ''));
            $grower = (string)($row['grower'] ?? '');
            $type = (string)($row['type'] ?? '');
            $carrier = trim((string)($row['carrier'] ?? ''));
            $notes = trim((string)($row['notes'] ?? ''));
            $date = (string)($row['date'] ?? '');
            $qty = (int)($row['quantity'] ?? 0);

            $pdf->SetFillColor(248, 250, 252);
            $pdf->SetDrawColor(203, 213, 225);
            $pdf->SetTextColor(15, 23, 42);

            $pdf->SetFont('Arial', 'B', 11);
            $pdf->Cell(0, 8, 'Receiving Information', 1, 1, 'L', true);

            $labelW = 48;
            // Carrier and Notes are OPTIONAL, but their rows are always shown.
            // If not completed, their values are intentionally left blank.
            $rows = [
                ['Grower', $grower],
                ['Bin Type', $type],
                ['Carrier', $carrier],
                ['Receiving Date', $date],
                ['Quantity', (string)$qty],
                ['Recorded At', $createdAt],
            ];

            if ($enteredBy !== '') {
                $rows[] = ['Entered By', $enteredBy];
            }

            foreach ($rows as [$label, $value]) {
                $pdf->SetFont('Arial', 'B', 10);
                $pdf->Cell($labelW, 8, $label, 1, 0, 'L');
                $pdf->SetFont('Arial', '', 10);
                $pdf->Cell(0, 8, $value, 1, 1, 'L');
            }

            // Notes row is always present; blank notes remain blank.
            $pdf->SetFont('Arial', 'B', 10);
            $pdf->Cell($labelW, 8, 'Notes', 1, 0, 'L');
            $x = $pdf->GetX();
            $pdf->SetFont('Arial', '', 10);

            $remainingW = 216 - 15 - $x;
            $pdf->MultiCell($remainingW, 6, $notes, 1, 'L');

            $pdf->Ln(8);
            $pdf->SetFont('Arial', 'B', 11);
            $pdf->Cell(0, 8, 'Balance After This Receipt', 1, 1, 'L', true);

            $growerTotal = (int)($summary['grower_empty_bins'] ?? 0);

            $pdf->SetFont('Arial', '', 10);
            $pdf->Cell(100, 8, 'Total Empty Bins for ' . $grower, 1, 0, 'L');
            $pdf->SetFont('Arial', 'B', 10);
            $pdf->Cell(0, 8, (string)$growerTotal, 1, 1, 'L');

            $pdf->Ln(12);
            $pdf->SetFont('Arial', '', 8);
            $pdf->SetTextColor(100, 116, 139);
            $pdf->MultiCell(0, 5, 'Automatically generated by SM Produce Empty Bin Receiving.', 0, 'L');

            $pdf->Output('F', $absolute);
        } catch (Throwable $e) {
            return ['ok'=>false, 'error'=>'PDF generation failed: ' . $e->getMessage()];
        }

        if (!is_file($absolute) || filesize($absolute) < 100) {
            return ['ok'=>false, 'error'=>'PDF file was not created correctly.'];
        }

        return [
            'ok'=>true,
            'path'=>$absolute,
            'filename'=>$filename,
            'url'=>'../data/empty_bin_reports/' . rawurlencode($filename),
        ];
    }
}


if (!function_exists('ebr_powershell_encoded_command')) {
    function ebr_powershell_encoded_command(string $script): string
    {
        // PowerShell -EncodedCommand expects UTF-16LE Base64.
        if (function_exists('mb_convert_encoding')) {
            $utf16 = mb_convert_encoding($script, 'UTF-16LE', 'UTF-8');
        } else {
            $utf16 = iconv('UTF-8', 'UTF-16LE', $script);
        }
        return 'powershell.exe -NoProfile -NonInteractive -ExecutionPolicy Bypass -EncodedCommand ' . base64_encode($utf16);
    }
}

if (!function_exists('ebr_ensure_pdf_print_queue')) {
    function ebr_ensure_pdf_print_queue(mysqli $mysqli): void
    {
        $mysqli->query("
            CREATE TABLE IF NOT EXISTS pdf_print_queue (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                module VARCHAR(50) NOT NULL DEFAULT 'empty_bins',
                pdf_path VARCHAR(600) NOT NULL,
                printer_name VARCHAR(255) NOT NULL,
                status ENUM('queued','claimed','printed','error','cancelled') NOT NULL DEFAULT 'queued',
                attempts INT NOT NULL DEFAULT 0,
                error_message TEXT NULL,
                claimed_at DATETIME NULL,
                completed_at DATETIME NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_pdf_print_queue_status (status, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }
}

if (!function_exists('ebr_print_pdf_windows')) {
    /**
     * Queue the PDF for the local interactive-user print agent.
     * Apache/XAMPP may run under a service account that cannot see per-user
     * printers or SumatraPDF. The agent runs in the SM Produce desktop session.
     */
    function ebr_print_pdf_windows(string $pdfPath, string $printer): array
    {
        global $mysqli;

        $printer = trim($printer);
        if ($printer === '') {
            return ['ok'=>false, 'skipped'=>true, 'error'=>'No report printer selected.'];
        }
        if (!is_file($pdfPath)) {
            return ['ok'=>false, 'error'=>'PDF file not found: ' . $pdfPath];
        }
        if (!($mysqli instanceof mysqli)) {
            return ['ok'=>false, 'error'=>'Database connection is unavailable for the PDF print queue.'];
        }

        ebr_ensure_pdf_print_queue($mysqli);

        $stmt = $mysqli->prepare("
            INSERT INTO pdf_print_queue(module, pdf_path, printer_name, status)
            VALUES('empty_bins', ?, ?, 'queued')
        ");
        if (!$stmt) {
            return ['ok'=>false, 'error'=>'Unable to prepare PDF print queue insert: ' . $mysqli->error];
        }

        $absolute = realpath($pdfPath) ?: $pdfPath;
        $stmt->bind_param('ss', $absolute, $printer);
        $ok = $stmt->execute();
        $jobId = $ok ? (int)$mysqli->insert_id : 0;
        $err = $stmt->error;
        $stmt->close();

        if (!$ok) {
            return ['ok'=>false, 'error'=>'Unable to queue PDF print job: ' . $err];
        }

        return [
            'ok'=>true,
            'verified'=>false,
            'queued'=>true,
            'job_id'=>$jobId,
            'method'=>'Local PDF Print Agent',
            'printer'=>$printer,
            'detail'=>'PDF queued for the SM Produce Windows user print agent.'
        ];
    }
}
