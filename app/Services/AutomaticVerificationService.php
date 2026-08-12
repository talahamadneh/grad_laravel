<?php

namespace App\Services;

use App\Models\Student;
use App\Models\Company;
use Illuminate\Support\Facades\DB;
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
            $student->update([
                'verification_status' => 'Pending',
            ]);

            return 'Pending';
        }

        // Critical account checks
        if (
            empty(trim($user->name ?? '')) ||
            empty(trim($user->email ?? '')) ||
            !filter_var($user->email, FILTER_VALIDATE_EMAIL) ||
            empty($user->password) ||
            strtolower($user->role) !== 'student'
        ) {
            $student->update([
                'verification_status' => 'Pending',
            ]);

            return 'Pending';
        }

        // Email must be verified
        if (!$user->email_verified_at) {
            $student->update([
                'verification_status' => 'Pending',
            ]);

            return 'Pending';
        }

        // Calculate score only after basic checks pass
        $result = $this->calculateStudentVerificationScore($student);

        // Automatic approval
        if ($result['verification_score'] >= 80) {
            $student->update([
                'verification_status' => 'Approved',
            ]);

            return 'Approved';
        }

        $student->update([
            'verification_status' => 'Pending',
        ]);

        return 'Pending';
    }

    public function calculateStudentVerificationScore(Student $student): array
    {
        $score = 0;

        $user = $student->user;

        // Name - 10 points
        if (!empty(trim($user->name ?? ''))) {
            $score += 10;
        }

        // Verified Email - 20 points
        if (
            !empty($user->email) &&
            filter_var($user->email, FILTER_VALIDATE_EMAIL) &&
            $user->email_verified_at
        ) {
            $score += 20;
        }

        // University - 15 points
        if (!empty(trim($student->university ?? ''))) {
            $score += 15;
        }

        // Major - 15 points
        if (!empty(trim($student->major ?? ''))) {
            $score += 15;
        }

        // Graduation Year - 10 points
        if (!empty($student->graduation_year)) {
            $score += 10;
        }

        // Phone - 10 points
        if (!empty(trim($student->phone ?? ''))) {
            $score += 10;
        }

        // Profile completeness - 20 points
        $profileCompletion = (int) ($student->profile_completion ?? 0);

        $score += round(($profileCompletion / 100) * 20);

        // Keep between 0 and 100
        $score = max(0, min(100, $score));

        // Recommendation
        if ($score >= 80) {
            $recommendation = 'Trusted';
        } elseif ($score >= 50) {
            $recommendation = 'Monitor';
        } else {
            $recommendation = 'Review Required';
        }

        return [
            'verification_score' => $score,
            'recommendation' => $recommendation,
        ];
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

    public function calculateCompanyVerificationScore(Company $company): array
    {
        $score = 0;

        // Company name - 15 points
        if (!empty(trim($company->company_name ?? ''))) {
            $score += 15;
        }

        // Industry - 15 points
        if (!empty(trim($company->industry ?? ''))) {
            $score += 15;
        }

        // Verified email - 20 points
        if (
            $company->user &&
            !empty($company->user->email) &&
            $company->user->email_verified_at
        ) {
            $score += 20;
        }

        // Description - 10 points
        if (!empty(trim($company->description ?? ''))) {
            $score += 10;
        }

        // Website - 5 points
        if (!empty(trim($company->website ?? ''))) {
            $score += 5;
        }

        // Phone - 5 points
        if (!empty(trim($company->phone ?? ''))) {
            $score += 5;
        }

        // Location - 5 points
        if (!empty(trim($company->location ?? ''))) {
            $score += 5;
        }

        // Company size - 5 points
        if (!empty(trim($company->company_size ?? ''))) {
            $score += 5;
        }

        // Founded year - 5 points
        if (!empty($company->founded_year)) {
            $score += 5;
        }

        // Stage - 5 points
        if (!empty(trim($company->stage ?? ''))) {
            $score += 5;
        }

        // Values - 5 points
        if (!empty(trim($company->values ?? ''))) {
            $score += 5;
        }

        // Benefits - 5 points
        if (!empty(trim($company->benefits ?? ''))) {
            $score += 5;
        }

        // Reports
        $reportsCount = DB::table('message_reports')
            ->where('reported_user_id', $company->user_id)
            ->count();

        $score -= ($reportsCount * 10);

        // Keep score between 0 and 100
        $score = max(0, min(100, $score));

        // Risk is based on actual risk signals, not profile completeness.
        if ($reportsCount >= 3) {
            $riskLevel = 'High';
        } elseif ($reportsCount > 0) {
            $riskLevel = 'Medium';
        } else {
            $riskLevel = 'Low';
        }

        // Recommendation
        if ($riskLevel === 'High') {
            $recommendation = 'Review Required';
        } elseif ($riskLevel === 'Medium') {
            $recommendation = 'Monitor';
        } else {
            $recommendation = 'Trusted';
        }

        return [
            'verification_score' => $score,
            'risk_level' => $riskLevel,
            'recommendation' => $recommendation,
            'reports_count' => $reportsCount,
        ];
    }
}