<?php

namespace App\Services;

use App\Models\Student;
use App\Models\Company;

class AutomaticVerificationService
{
    /**
     * Automatically verify a student.
     *
     * Returns:
     * - Approved if all basic checks pass.
     * - Pending if something requires manual review.
     */
    public function verifyStudent(Student $student): string
    {
        $user = $student->user;

        // No related user
        if (!$user) {
            $status = 'Pending';
            $student->update([
                'verification_status' => $status,
            ]);

            return $status;
        }

        // Name must exist
        if (empty(trim($user->name))) {
            $status = 'Pending';
            $student->update([
                'verification_status' => $status,
            ]);

            return $status;
        }

        // Email must exist and be valid
        if (
            empty(trim($user->email)) ||
            !filter_var($user->email, FILTER_VALIDATE_EMAIL)
        ) {
            $status = 'Pending';
            $student->update([
                'verification_status' => $status,
            ]);

            return $status;
        }

        // Password must exist
        if (empty($user->password)) {
            $status = 'Pending';
            $student->update([
                'verification_status' => $status,
            ]);

            return $status;
        }

        // Must actually be a student account
        if (strtolower($user->role) !== 'student') {
            $status = 'Pending';
            $student->update([
                'verification_status' => $status,
            ]);

            return $status;
        }

        // All basic checks passed
        $status = 'Approved';

        $student->update([
            'verification_status' => $status,
        ]);

        return $status;
    }


    /**
     * Automatically verify a company.
     *
     * Returns:
     * - Approved if all basic checks pass.
     * - Pending if something requires manual review.
     */
    public function verifyCompany(Company $company): string
    {
        $user = $company->user;

        // No related user
        if (!$user) {
            $status = 'Pending';

            $company->update([
                'approval_status' => $status,
                'is_verified' => false,
            ]);

            return $status;
        }

        // Company name must exist
        if (empty(trim($company->company_name))) {
            $status = 'Pending';

            $company->update([
                'approval_status' => $status,
                'is_verified' => false,
            ]);

            return $status;
        }

        // Email must exist and be valid
        if (
            empty(trim($user->email)) ||
            !filter_var($user->email, FILTER_VALIDATE_EMAIL)
        ) {
            $status = 'Pending';

            $company->update([
                'approval_status' => $status,
                'is_verified' => false,
            ]);

            return $status;
        }

        // Password must exist
        if (empty($user->password)) {
            $status = 'Pending';

            $company->update([
                'approval_status' => $status,
                'is_verified' => false,
            ]);

            return $status;
        }

        // Must actually be a company account
        if (strtolower($user->role) !== 'company') {
            $status = 'Pending';

            $company->update([
                'approval_status' => $status,
                'is_verified' => false,
            ]);

            return $status;
        }

        // Industry is required for automatic approval
        if (empty(trim($company->industry ?? ''))) {
            $status = 'Pending';

            $company->update([
                'approval_status' => $status,
                'is_verified' => false,
            ]);

            return $status;
        }

        // All basic verification checks passed
        $status = 'Approved';

        $company->update([
            'approval_status' => $status,
            'is_verified' => true,
        ]);

        return $status;
    }
}