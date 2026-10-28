<?php

function sss_monthly_salary_credit($monthlySalary) {
    $monthlySalary = max(0, (float)$monthlySalary);
    if ($monthlySalary === 0.0) {
        return 0.0;
    }

    if ($monthlySalary < 5250) {
        return 5000.0;
    }

    $salaryCredit = 5500 + (floor(($monthlySalary - 5250) / 500) * 500);
    return (float)min(35000, $salaryCredit);
}

function calculate_statutory_shares($monthlySalary) {
    $monthlySalary = max(0, (float)$monthlySalary);
    $sssSalaryCredit = sss_monthly_salary_credit($monthlySalary);
    $pagibigSalaryBase = min($monthlySalary, 10000);

    return [
        'sss_employee' => round($sssSalaryCredit * 0.05, 2),
        'sss_employer' => round($sssSalaryCredit * 0.10, 2),
        'philhealth_employee' => round($monthlySalary * 0.025, 2),
        'philhealth_employer' => round($monthlySalary * 0.025, 2),
        'pagibig_employee' => round($pagibigSalaryBase * ($monthlySalary > 1500 ? 0.02 : 0.01), 2),
        'pagibig_employer' => round($pagibigSalaryBase * 0.02, 2),
    ];
}

function statutory_share_for_cutoff($monthlyShare, $cutoff) {
    $firstCutoffShare = round((float)$monthlyShare / 2, 2);
    if ($cutoff === 'first') {
        return $firstCutoffShare;
    }

    return round((float)$monthlyShare - $firstCutoffShare, 2);
}