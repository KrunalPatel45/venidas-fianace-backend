<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MonthlyMemberStatementExcelController extends Controller
{
    private function money($value): float
    {
        $clean = str_replace(
            [',', '₹', ' '],
            '',
            (string) ($value ?? '0')
        );

        return is_numeric($clean)
            ? (float) $clean
            : 0.0;
    }

    private function labels(bool $gu): array
    {
        if ($gu) {
            return [
                'company' => 'વેણીડાસ ફાઇનાન્સ',
                'title' => 'સભ્યોની માસિક રોકાણ અને કમાણી વિગત',
                'sr' => 'અનુ. નં.',
                'name' => 'સભ્યનું નામ',
                'monthly' => 'માસિક હપ્તો',
                'investment' => 'કુલ રોકાણ / મૂડી',
                'loan' => 'લોનની રકમ',
                'interest' => 'ચૂકવેલું વ્યાજ',
                'total' => 'કુલ રકમ',
                'earning' => 'વહેંચેલી કમાણી',
                'with_earning' => 'રોકાણ + કમાણી',
                'grand_total' => 'કુલ',
                'collected' => 'કુલ વસૂલ થયેલું વ્યાજ',
                'distributed' => 'કુલ વહેંચેલી કમાણી',
                'members' => 'કુલ સભ્યો',
                'note' => 'નોંધ: કમાણીનું વિતરણ સભ્યોના ચૂકવેલા કુલ રોકાણના પ્રમાણમાં થાય છે.',
            ];
        }

        return [
            'company' => 'VENIDAS FINANCE',
            'title' => 'Monthly Member Investment and Earnings Statement',
            'sr' => 'Sr. No.',
            'name' => 'Member Name',
            'monthly' => 'Monthly Installment',
            'investment' => 'Total Investment',
            'loan' => 'Loan Amount',
            'interest' => 'Interest Paid',
            'total' => 'Total Amount',
            'earning' => 'Distributed Earnings',
            'with_earning' => 'Investment + Earnings',
            'grand_total' => 'TOTAL',
            'collected' => 'Total Collected Interest',
            'distributed' => 'Total Distributed Earnings',
            'members' => 'Total Members',
            'note' => 'Note: Earnings are distributed proportionally to paid member investments.',
        ];
    }

    public function export(Request $request): StreamedResponse
    {
        // Reuse existing calculation API.
        $response = app(
            MonthlyMemberStatementController::class
        )->index($request);

        $payload = $response->getData(true);
        $report = $payload['data'] ?? null;

        if (
            !is_array($report)
            || !isset(
                $report['rows'],
                $report['summary'],
                $report['month']
            )
        ) {
            abort(422, 'Invalid monthly statement data.');
        }

        $lang = $report['language'] ?? 'gu';
        $gu = $lang === 'gu';
        $month = $report['month'];
        $labels = $this->labels($gu);

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        $sheet->setTitle(
            $gu ? 'માસિક સ્ટેટમેન્ટ' : 'Monthly Statement'
        );

        $sheet->setShowGridlines(false);

        $spreadsheet->getDefaultStyle()
            ->getFont()
            ->setName('Nirmala UI')
            ->setSize(11);

        $widths = [
            'A' => 11,
            'B' => 44,
            'C' => 19,
            'D' => 23,
            'E' => 21,
            'F' => 20,
            'G' => 20,
            'H' => 24,
            'I' => 25,
        ];

        foreach ($widths as $column => $width) {
            $sheet->getColumnDimension($column)
                ->setWidth($width);
        }

        $sheet->getDefaultRowDimension()
            ->setRowHeight(27);

        // Main title.
        $sheet->mergeCells('A1:I2');
        $sheet->setCellValue('A1', $labels['company']);

        $sheet->getStyle('A1:I2')->applyFromArray([
            'font' => [
                'bold' => true,
                'size' => 20,
                'color' => ['rgb' => 'FFFFFF'],
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '172554'],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ]);

        $sheet->getRowDimension(1)->setRowHeight(27);
        $sheet->getRowDimension(2)->setRowHeight(27);

        // Subtitle.
        $sheet->mergeCells('A3:I3');
        $sheet->setCellValue('A3', $labels['title']);

        $sheet->getStyle('A3:I3')->applyFromArray([
            'font' => [
                'bold' => true,
                'size' => 14,
                'color' => ['rgb' => '1E3A8A'],
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'DBEAFE'],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ]);

        $sheet->getRowDimension(3)->setRowHeight(36);

        // Month.
        $sheet->mergeCells('A4:I4');

        $monthDate = \DateTimeImmutable::createFromFormat(
            '!Y-m',
            $month
        );

        $monthText = $monthDate
            ? strtoupper($monthDate->format('F Y'))
            : $month;

        $sheet->setCellValue('A4', $monthText);

        $sheet->getStyle('A4:I4')->applyFromArray([
            'font' => [
                'bold' => true,
                'size' => 12,
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ]);

        $sheet->getRowDimension(4)->setRowHeight(30);

        // Nine headings. Account Number excluded.
        $headers = [
            $labels['sr'],
            $labels['name'],
            $labels['monthly'],
            $labels['investment'],
            $labels['loan'],
            $labels['interest'],
            $labels['total'],
            $labels['earning'],
            $labels['with_earning'],
        ];

        foreach ($headers as $index => $heading) {
            $column = chr(65 + $index);

            $sheet->setCellValue(
                $column . '6',
                $heading
            );
        }

        $sheet->getStyle('A6:I6')->applyFromArray([
            'font' => [
                'bold' => true,
                'color' => ['rgb' => 'FFFFFF'],
                'size' => 11,
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '2563EB'],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
                'wrapText' => true,
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => 'CBD5E1'],
                ],
            ],
        ]);

        $sheet->getRowDimension(6)->setRowHeight(48);

        $rowNumber = 7;

        foreach ($report['rows'] as $row) {
            $name = $gu
                ? ($row['name_gu'] ?: $row['name'])
                : $row['name'];

            if (!empty($row['member_code'])) {
                $name .= ' (' . $row['member_code'] . ')';
            }

            $sheet->setCellValue(
                "A{$rowNumber}",
                (int) $row['serial_number']
            );

            $sheet->setCellValueExplicit(
                "B{$rowNumber}",
                (string) $name,
                DataType::TYPE_STRING
            );

            $values = [
                'C' => $row['monthly_amount'],
                'D' => $row['total_investment'],
                'E' => $row['loan_amount'],
                'F' => $row['interest_paid'],
                'G' => $row['total_amount'],
                'H' => $row['distributed_earning'],
                'I' => $row['investment_with_earning'],
            ];

            foreach ($values as $column => $value) {
                $sheet->setCellValue(
                    "{$column}{$rowNumber}",
                    $this->money($value)
                );
            }

            $background = $rowNumber % 2 === 0
                ? 'EFF6FF'
                : 'FFFFFF';

            $sheet->getStyle("A{$rowNumber}:I{$rowNumber}")
                ->applyFromArray([
                    'fill' => [
                        'fillType' => Fill::FILL_SOLID,
                        'startColor' => [
                            'rgb' => $background,
                        ],
                    ],
                    'borders' => [
                        'allBorders' => [
                            'borderStyle' => Border::BORDER_HAIR,
                            'color' => ['rgb' => 'CBD5E1'],
                        ],
                    ],
                    'alignment' => [
                        'vertical' => Alignment::VERTICAL_CENTER,
                    ],
                ]);

            $sheet->getStyle("C{$rowNumber}:I{$rowNumber}")
                ->getNumberFormat()
                ->setFormatCode(
                    '"₹"#,##0.00;[Red]("₹"#,##0.00)'
                );

            $sheet->getStyle("C{$rowNumber}:I{$rowNumber}")
                ->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_RIGHT);

            $sheet->getRowDimension($rowNumber)
                ->setRowHeight(29);

            $rowNumber++;
        }

        // Grand total row.
        $summary = $report['summary'];
        $totalRow = $rowNumber;

        $sheet->mergeCells("A{$totalRow}:B{$totalRow}");

        $sheet->setCellValue(
            "A{$totalRow}",
            $labels['grand_total']
        );

        $totals = [
            'C' => $summary['monthly_amount'],
            'D' => $summary['total_investment'],
            'E' => $summary['loan_amount'],
            'F' => $summary['interest_paid_by_members'],
            'G' => $summary['total_amount'],
            'H' => $summary['distributed_earning'],
            'I' => $summary['investment_with_earning'],
        ];

        foreach ($totals as $column => $value) {
            $sheet->setCellValue(
                "{$column}{$totalRow}",
                $this->money($value)
            );
        }

        $sheet->getStyle("A{$totalRow}:I{$totalRow}")
            ->applyFromArray([
                'font' => [
                    'bold' => true,
                    'color' => ['rgb' => 'FFFFFF'],
                ],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => '1E40AF'],
                ],
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => Border::BORDER_THIN,
                        'color' => ['rgb' => 'FFFFFF'],
                    ],
                ],
                'alignment' => [
                    'vertical' => Alignment::VERTICAL_CENTER,
                ],
            ]);

        $sheet->getStyle("C{$totalRow}:I{$totalRow}")
            ->getNumberFormat()
            ->setFormatCode('"₹"#,##0.00');

        $sheet->getRowDimension($totalRow)
            ->setRowHeight(36);

        // Bottom summary.
        $summaryStart = $totalRow + 2;

        $summaryRows = [
            [
                $labels['members'],
                $summary['member_count'],
                false,
            ],
            [
                $labels['collected'],
                $summary['collected_interest'],
                true,
            ],
            [
                $labels['distributed'],
                $summary['distributed_earning'],
                true,
            ],
        ];

        foreach ($summaryRows as $index => $item) {
            $r = $summaryStart + $index;

            $sheet->mergeCells("B{$r}:D{$r}");
            $sheet->mergeCells("E{$r}:F{$r}");

            $sheet->setCellValue("B{$r}", $item[0]);

            $sheet->setCellValue(
                "E{$r}",
                $item[2]
                    ? $this->money($item[1])
                    : (int) $item[1]
            );

            $sheet->getStyle("B{$r}:F{$r}")
                ->applyFromArray([
                    'font' => [
                        'bold' => true,
                        'color' => ['rgb' => '1E3A8A'],
                    ],
                    'fill' => [
                        'fillType' => Fill::FILL_SOLID,
                        'startColor' => ['rgb' => 'EFF6FF'],
                    ],
                    'borders' => [
                        'bottom' => [
                            'borderStyle' => Border::BORDER_THIN,
                            'color' => ['rgb' => 'CBD5E1'],
                        ],
                    ],
                ]);

            if ($item[2]) {
                $sheet->getStyle("E{$r}:F{$r}")
                    ->getNumberFormat()
                    ->setFormatCode('"₹"#,##0.00');
            }

            $sheet->getRowDimension($r)->setRowHeight(28);
        }

        $noteRow = $summaryStart + 5;

        $sheet->mergeCells("A{$noteRow}:I{$noteRow}");
        $sheet->setCellValue(
            "A{$noteRow}",
            $labels['note']
        );

        $sheet->getStyle("A{$noteRow}:I{$noteRow}")
            ->applyFromArray([
                'font' => [
                    'italic' => true,
                    'color' => ['rgb' => '64748B'],
                ],
                'alignment' => [
                    'wrapText' => true,
                ],
            ]);

        $sheet->getRowDimension($noteRow)->setRowHeight(35);

        // Print settings.
        $page = $sheet->getPageSetup();

        $page->setOrientation(PageSetup::ORIENTATION_LANDSCAPE);
        $page->setPaperSize(PageSetup::PAPERSIZE_A3);
        $page->setFitToPage(true);
        $page->setFitToWidth(1);
        $page->setFitToHeight(0);

        $page->setRowsToRepeatAtTopByStartAndEnd(1, 6);
        $page->setPrintArea("A1:I{$noteRow}");

        $sheet->freezePane('C7');

        $sheet->getPageMargins()->setTop(0.4);
        $sheet->getPageMargins()->setBottom(0.4);
        $sheet->getPageMargins()->setLeft(0.3);
        $sheet->getPageMargins()->setRight(0.3);

        $sheet->getHeaderFooter()->setOddFooter(
            '&CVENIDAS FINANCE | Page &P of &N'
        );

        $filename = sprintf(
            'venidas-monthly-statement-%s-%s.xlsx',
            $month,
            $lang
        );

        return response()->streamDownload(
            function () use ($spreadsheet) {
                $writer = new Xlsx($spreadsheet);
                $writer->setPreCalculateFormulas(false);
                $writer->save('php://output');

                $spreadsheet->disconnectWorksheets();
            },
            $filename,
            [
                'Content-Type' =>
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Cache-Control' => 'no-store, private',
            ]
        );
    }
}