<?php
require_once __DIR__ . '/empty_bin_report.php';

if (!function_exists('fbr_ensure_settings')) {
    function fbr_ensure_settings(mysqli $mysqli): void {
        $mysqli->query("CREATE TABLE IF NOT EXISTS full_bin_print_settings (
            id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
            label_printer_id INT NULL,
            report_printer VARCHAR(255) NULL,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $cols = [];
        if ($cr = $mysqli->query("SHOW COLUMNS FROM full_bin_print_settings")) {
            while ($c = $cr->fetch_assoc()) $cols[strtolower((string)$c['Field'])] = true;
        }
        if (!isset($cols['label_template_id'])) {
            $mysqli->query("ALTER TABLE full_bin_print_settings ADD COLUMN label_template_id INT NULL AFTER label_printer_id");
        }
        $mysqli->query("INSERT IGNORE INTO full_bin_print_settings(id,label_printer_id,label_template_id,report_printer) VALUES(1,NULL,NULL,NULL)");
    }
}
if (!function_exists('fbr_get_settings')) {
    function fbr_get_settings(mysqli $mysqli): array
    {
        fbr_ensure_settings($mysqli);

        $defaults = [
            'label_printer_id' => 0,
            'label_template_id' => 0,
            'report_printer' => '',
        ];

        try {
            $res = $mysqli->query("
                SELECT label_printer_id, label_template_id, report_printer
                FROM full_bin_print_settings
                WHERE id=1
                LIMIT 1
            ");
            $row = $res ? $res->fetch_assoc() : null;
            if (!$row) return $defaults;

            return [
                'label_printer_id' => (int)($row['label_printer_id'] ?? 0),
                'label_template_id' => (int)($row['label_template_id'] ?? 0),
                'report_printer' => trim((string)($row['report_printer'] ?? '')),
            ];
        } catch (Throwable $e) {
            return $defaults;
        }
    }
}
if (!function_exists('fbr_set_label_printer')) {
    function fbr_set_label_printer(mysqli $mysqli,int $id): bool {
        fbr_ensure_settings($mysqli);
        $stmt=$mysqli->prepare("UPDATE full_bin_print_settings SET label_printer_id=?,updated_at=CURRENT_TIMESTAMP WHERE id=1");
        $v=$id>0?$id:null; $stmt->bind_param('i',$v); $ok=$stmt->execute(); $stmt->close(); return $ok;
    }
}
if (!function_exists('fbr_set_label_template')) {
    function fbr_set_label_template(mysqli $mysqli, int $templateId): bool
    {
        fbr_ensure_settings($mysqli);
        $stmt = $mysqli->prepare("
            UPDATE full_bin_print_settings
            SET label_template_id=?, updated_at=CURRENT_TIMESTAMP
            WHERE id=1
        ");
        if (!$stmt) return false;
        $value = $templateId > 0 ? $templateId : null;
        $stmt->bind_param('i', $value);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }
}

if (!function_exists('fbr_set_report_printer')) {
    function fbr_set_report_printer(mysqli $mysqli,string $name): bool {
        fbr_ensure_settings($mysqli);
        $stmt=$mysqli->prepare("UPDATE full_bin_print_settings SET report_printer=?,updated_at=CURRENT_TIMESTAMP WHERE id=1");
        $v=$name!==''?$name:null; $stmt->bind_param('s',$v); $ok=$stmt->execute(); $stmt->close(); return $ok;
    }
}
if (!function_exists('fbr_ensure_receipts')) {
    function fbr_ensure_receipts(mysqli $mysqli): void {
        $mysqli->query("CREATE TABLE IF NOT EXISTS full_bin_receipts (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            group_id BIGINT NOT NULL,
            grower VARCHAR(100) NOT NULL,
            variety VARCHAR(100) NOT NULL,
            type VARCHAR(100) NOT NULL,
            lot VARCHAR(120) NULL,
            receiving_date DATE NOT NULL,
            quantity INT NOT NULL,
            report_pdf VARCHAR(255) NULL,
            notes TEXT NULL,
            entered_by VARCHAR(120) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_fbr_group(group_id), INDEX idx_fbr_date(receiving_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $cols=[];
        if($cr=$mysqli->query("SHOW COLUMNS FROM full_bin_receipts")){
            while($c=$cr->fetch_assoc()) $cols[strtolower((string)$c['Field'])]=true;
        }
        if(!isset($cols['is_deleted'])){
            @$mysqli->query("ALTER TABLE full_bin_receipts ADD COLUMN is_deleted TINYINT(1) NOT NULL DEFAULT 0 AFTER report_pdf");
        }
        if(!isset($cols['notes'])){
            @$mysqli->query("ALTER TABLE full_bin_receipts ADD COLUMN notes TEXT NULL AFTER lot");
        }
    }
}

if (!function_exists('fbr_grower_inventory')) {
    /**
     * Current AVAILABLE Full Bin inventory for one grower.
     * Rows are split by Variety + Bin Type + Lot.
     */
    function fbr_grower_inventory(mysqli $mysqli,string $grower): array {
        $grower=trim($grower);
        if($grower==='') return ['rows'=>[],'total'=>0];

        $sql="
            SELECT
                COALESCE(NULLIF(TRIM(vl.name),''),'Unknown') AS variety,
                COALESCE(NULLIF(TRIM(tl.name),''),'Unknown') AS bin_type,
                COALESCE(NULLIF(TRIM(bi.lot),''),'') AS lot,
                COUNT(*) AS available_bins
            FROM bins_ingresso bi
            LEFT JOIN growers_list gp   ON gp.id=bi.grower_id
            LEFT JOIN varieties_list vl ON vl.id=bi.variety_id
            LEFT JOIN bin_types_list tl ON tl.id=bi.type_id
            WHERE bi.status='AVAILABLE'
              AND LOWER(TRIM(gp.name))=LOWER(TRIM(?))
            GROUP BY
                COALESCE(NULLIF(TRIM(vl.name),''),'Unknown'),
                COALESCE(NULLIF(TRIM(tl.name),''),'Unknown'),
                COALESCE(NULLIF(TRIM(bi.lot),''),'—')
            ORDER BY
                variety ASC,
                bin_type ASC,
                lot ASC
        ";

        $rows=[];
        $total=0;
        try{
            $st=$mysqli->prepare($sql);
            $st->bind_param('s',$grower);
            $st->execute();
            $res=$st->get_result();
            while($row=$res->fetch_assoc()){
                $row['available_bins']=(int)($row['available_bins']??0);
                $rows[]=$row;
            }
            $st->close();
        }catch(Throwable $e){
            return ['rows'=>[],'total'=>0,'error'=>$e->getMessage()];
        }

        // Calculate the grower total independently from the grouped table.
        // This guarantees that the number printed at the bottom is the exact
        // count of AVAILABLE full bins for the selected grower.
        try{
            $ct=$mysqli->prepare("SELECT COUNT(*) AS total
                FROM bins_ingresso bi
                LEFT JOIN growers_list gp ON gp.id=bi.grower_id
                WHERE bi.status='AVAILABLE'
                  AND LOWER(TRIM(gp.name))=LOWER(TRIM(?))");
            $ct->bind_param('s',$grower);
            $ct->execute();
            $countRow=$ct->get_result()->fetch_assoc();
            $total=(int)($countRow['total']??0);
            $ct->close();
        }catch(Throwable $e){
            $total=array_sum(array_map(static fn($row)=>(int)($row['available_bins']??0),$rows));
        }

        return ['rows'=>$rows,'total'=>$total];
    }
}

if (!function_exists('fbr_report_file')) {
    function fbr_report_file(int $id,string $date): array {
        $safe=preg_replace('/[^0-9\-]/','',$date);
        $fn=sprintf('full_bins_%s_%06d.pdf',$safe?:date('Y-m-d'),$id);
        $path=__DIR__.'/../data/full_bin_reports/'.$fn;
        return ['filename'=>$fn,'path'=>$path,'exists'=>is_file($path)&&filesize($path)>100,'url'=>'../data/full_bin_reports/'.rawurlencode($fn)];
    }
}
if (!function_exists('fbr_generate_receipt_pdf')) {
    function fbr_generate_receipt_pdf(array $r,array $summary=[]): array {
        $fpdf=__DIR__.'/../vendor/setasign/fpdf/fpdf.php';
        if(!is_file($fpdf)) return ['ok'=>false,'error'=>'FPDF library not found.'];
        require_once $fpdf;

        $dir=__DIR__.'/../data/full_bin_reports';
        if(!is_dir($dir)&&!@mkdir($dir,0775,true)&&!is_dir($dir)){
            return ['ok'=>false,'error'=>'Unable to create full_bin_reports directory.'];
        }

        $id=(int)($r['id']??0);
        $date=(string)($r['receiving_date']??date('Y-m-d'));
        $createdAt=trim((string)($r['created_at']??''));
        if($createdAt==='') $createdAt=date('Y-m-d H:i:s');

        $grower=trim((string)($r['grower']??''));
        $inventoryRows=is_array($summary['inventory_rows']??null)
            ? $summary['inventory_rows']
            : [];
        $growerTotal=(int)($summary['grower_total']??0);

        $f=fbr_report_file($id,$date);

        // Fit long table text without overflowing the fixed columns.
        $fitText=function(FPDF $pdf,string $text,float $maxWidth,int $startSize=9,int $minSize=6): int {
            $size=$startSize;
            while($size>$minSize){
                $pdf->SetFont('Arial','',$size);
                if($pdf->GetStringWidth($text)<=$maxWidth-2) break;
                $size--;
            }
            return $size;
        };

        try{
            $pdf=new FPDF('P','mm','Letter');
            $pdf->SetMargins(15,14,15);
            $pdf->SetAutoPageBreak(true,16);
            $pdf->AddPage();

            $pageW=216;
            $contentW=186;

            /* ── HEADER ─────────────────────────────────────────────── */
            $logo=__DIR__.'/../logo/logo.png';
            if(is_file($logo)) $pdf->Image($logo,15,12,28);

            $pdf->SetXY(48,13);
            $pdf->SetTextColor(15,23,42);
            $pdf->SetFont('Arial','B',18);
            $pdf->Cell(0,8,'SM PRODUCE LTD',0,1,'L');

            $pdf->SetX(48);
            $pdf->SetFont('Arial','B',13);
            $pdf->Cell(0,7,'FULL BIN RECEIVING REPORT',0,1,'L');

            $pdf->SetX(48);
            $pdf->SetFont('Arial','',9);
            $pdf->Cell(70,5,'Receipt #'.$id,0,0,'L');
            $pdf->Cell(0,5,$createdAt,0,1,'R');

            $pdf->Ln(7);
            $pdf->SetDrawColor(15,23,42);
            $pdf->SetLineWidth(.45);
            $pdf->Line(15,$pdf->GetY(),201,$pdf->GetY());
            $pdf->Ln(5);

            /* ── RECEIVING DETAILS ─────────────────────────────────── */
            $pdf->SetFillColor(239,246,255);
            $pdf->SetTextColor(15,23,42);
            $pdf->SetFont('Arial','B',11);
            $pdf->Cell($contentW,8,'RECEIVING DETAILS',1,1,'L',true);

            $items=[
                ['Grower',$grower],
                ['Variety',(string)($r['variety']??'')],
                ['Bin Type',(string)($r['type']??'')],
                ['Lot',(string)($r['lot']??'')],
                ['Notes',(string)($r['notes']??'')],
                ['Receiving Date',$date],
                ['Bins Received',(string)((int)($r['quantity']??0))],
            ];

            $entered=trim((string)($r['entered_by']??''));
            if($entered!=='') $items[]=['Entered By',$entered];

            foreach($items as [$label,$value]){
                $pdf->SetFillColor(248,250,252);
                $pdf->SetFont('Arial','B',9);
                $pdf->Cell(48,7.5,$label,1,0,'L',true);
                $pdf->SetFont('Arial','',9);
                $pdf->Cell($contentW-48,7.5,$value,1,1,'L');
            }

            $pdf->Ln(7);

            /* ── INVENTORY TITLE ───────────────────────────────────── */
            $pdf->SetFillColor(239,246,255);
            $pdf->SetFont('Arial','B',11);
            $pdf->Cell($contentW,8,'INVENTORY AFTER THIS RECEIPT',1,1,'L',true);

            $pdf->SetFillColor(248,250,252);
            $pdf->SetFont('Arial','B',10);
            $pdf->Cell($contentW,7.5,$grower!==''?$grower:'GROWER',1,1,'L',true);

            /* ── INVENTORY TABLE ───────────────────────────────────── */
            // 186 mm total
            $wVar=57;
            $wType=42;
            $wLot=51;
            $wQty=36;

            $drawTableHeader=function() use($pdf,$wVar,$wType,$wLot,$wQty){
                $pdf->SetFillColor(226,232,240);
                $pdf->SetTextColor(15,23,42);
                $pdf->SetFont('Arial','B',8);
                $pdf->Cell($wVar,7,'VARIETY',1,0,'L',true);
                $pdf->Cell($wType,7,'BIN TYPE',1,0,'L',true);
                $pdf->Cell($wLot,7,'LOT',1,0,'L',true);
                $pdf->Cell($wQty,7,'AVAILABLE BINS',1,1,'C',true);
            };
            $drawTableHeader();

            if(!$inventoryRows){
                $pdf->SetFont('Arial','I',9);
                $pdf->Cell($contentW,8,'No AVAILABLE Full Bins for this grower.',1,1,'L');
            }else{
                foreach($inventoryRows as $row){
                    // Repeat heading if auto-page-break would split the next row.
                    if($pdf->GetY()>247){
                        $pdf->AddPage();
                        $pdf->SetFont('Arial','B',11);
                        $pdf->Cell($contentW,8,'INVENTORY CONTINUED — '.$grower,1,1,'L');
                        $drawTableHeader();
                    }

                    $var=(string)($row['variety']??'');
                    $type=(string)($row['bin_type']??'');
                    $lot=(string)($row['lot']??'');
                    $qty=(int)($row['available_bins']??0);

                    $s=$fitText($pdf,$var,$wVar,9,6);
                    $pdf->SetFont('Arial','',$s);
                    $pdf->Cell($wVar,7,$var,1,0,'L');

                    $s=$fitText($pdf,$type,$wType,9,6);
                    $pdf->SetFont('Arial','',$s);
                    $pdf->Cell($wType,7,$type,1,0,'L');

                    $s=$fitText($pdf,$lot,$wLot,9,6);
                    $pdf->SetFont('Arial','',$s);
                    $pdf->Cell($wLot,7,$lot,1,0,'L');

                    $pdf->SetFont('Arial','B',9);
                    $pdf->Cell($wQty,7,(string)$qty,1,1,'C');
                }
            }

            // TOTAL GROWER only once, after every inventory row.
            if($pdf->GetY()>250) $pdf->AddPage();
            $pdf->SetFillColor(226,232,240);
            $pdf->SetFont('Arial','B',10);
            $pdf->Cell($wVar+$wType+$wLot,8,'TOTAL GROWER',1,0,'R',true);
            $pdf->Cell($wQty,8,(string)$growerTotal,1,1,'C',true);

            $pdf->Ln(10);
            $pdf->SetTextColor(100,116,139);
            $pdf->SetFont('Arial','',8);
            $pdf->MultiCell(
                0,5,
                'Automatically generated by SM Produce Full Bin Receiving. Inventory includes all AVAILABLE bins for the grower at report generation time.',
                0,'L'
            );

            $pdf->Output('F',$f['path']);
        }catch(Throwable $e){
            return ['ok'=>false,'error'=>'PDF generation failed: '.$e->getMessage()];
        }

        return is_file($f['path'])&&filesize($f['path'])>100
            ? ['ok'=>true]+$f
            : ['ok'=>false,'error'=>'PDF was not created correctly.'];
    }
}
