<?php

namespace App\Services;

use App\Models\JobPost;
use App\Models\SavedJob;
use App\Models\Resume;

class JobMatchingService
{
    public function getRecommendedJobs($student)
    {
        $resume = Resume::where('student_id', $student->id)
            ->latest()
            ->first();

        $studentSkills = collect($resume?->skills ?? [])
            ->pluck('name')
            ->map(fn($skill) => strtolower(trim($skill)))
            ->toArray();

        $savedIds = SavedJob::where('student_id', $student->id)
            ->pluck('job_post_id')
            ->toArray();

        $jobs = JobPost::with([
            'company',
            'skills'
        ])
            ->where('status', 'Open')
            ->get();

        $recommendedJobs = $jobs->map(function ($job) use ($student, $studentSkills, $savedIds) {

            $jobSkills = $job->skills
                ->pluck('name')
                ->map(fn($skill) => strtolower(trim($skill)))
                ->toArray();

            $matchingSkills = array_values(array_intersect($studentSkills, $jobSkills));

            $missingSkills = array_values(array_diff($jobSkills, $studentSkills));

            $matchedSkillsCount = count($matchingSkills);
            $totalSkillsCount = count($jobSkills);

            $reasons = [];


            $skillScore = ($matchedSkillsCount / max($totalSkillsCount, 1)) * 80;

            if ($matchedSkillsCount > 0) {
                $reasons[] = "Matches your skills: " . implode(", ", $matchingSkills);
            }

            $locationScore = 0;
            if (
                $student->location &&
                $job->location &&
                strtolower(trim($student->location)) === strtolower(trim($job->location))
            ) {
                $locationScore = 10;
                $reasons[] = "Matches your preferred location";
            }

            $typeScore = 0;
            if (
                $student->preferred_employment_type &&
                $job->employment_type &&
                strtolower(trim($student->preferred_employment_type)) === strtolower(trim($job->employment_type))
            ) {
                $typeScore = 5;
                $reasons[] = "Matches your preferred employment type";
            }

            $majorScore = 0;
            if (
                $student->major &&
                $job->required_major &&
                strtolower(trim($student->major)) === strtolower(trim($job->required_major))
            ) {
                $majorScore = 5;
                $reasons[] = "Matches your major";
            }

            $totalScore = round($skillScore + $locationScore + $typeScore + $majorScore);

            if ($totalScore >= 90) {
                $level = "Excellent Match";
            } elseif ($totalScore >= 75) {
                $level = "Good Match";
            } elseif ($totalScore >= 50) {
                $level = "Fair Match";
            } else {
                $level = "Low Match";
            }

            return [
                "job_id" => $job->id,
                "title" => $job->title,
                "company" => $job->company->company_name ?? null,
                "location" => $job->location,
                "salary" => $job->salary,
                "employment_type" => $job->employment_type,
                "work_mode" => $job->work_mode,
                "match" => $totalScore,
                "recommendation_level" => $level,
                "matching_skills" => $matchingSkills,
                "missing_skills" => $missingSkills,
                "reasons" => $reasons,
                "is_saved" => in_array($job->id, $savedIds)
            ];

        });

        return $recommendedJobs
            ->sortByDesc('match')
            ->values();
    }

    public function calculateMatch($student, $job)
    {
        $resume = Resume::where('student_id', $student->id)
            ->latest()
            ->first();

        $studentSkills = collect($resume?->skills ?? [])
            ->pluck('name')
            ->map(fn($skill) => strtolower(trim($skill)))
            ->toArray();

        $jobSkills = $job->skills
            ->pluck('name')
            ->map(fn($skill) => strtolower(trim($skill)))
            ->toArray();

        $matchingSkills = array_values(array_intersect($studentSkills, $jobSkills));

        $missingSkills = array_values(array_diff($jobSkills, $studentSkills));

        $matchedSkillsCount = count($matchingSkills);
        $totalSkillsCount = count($jobSkills);



        $skillScore = ($matchedSkillsCount / max($totalSkillsCount, 1)) * 80;

        $locationScore = 0;
        if (
            $student->location &&
            $job->location &&
            strtolower(trim($student->location)) === strtolower(trim($job->location))
        ) {
            $locationScore = 10;
        }

        $typeScore = 0;
        if (
            $student->preferred_employment_type &&
            $job->employment_type &&
            strtolower(trim($student->preferred_employment_type)) === strtolower(trim($job->employment_type))
        ) {
            $typeScore = 5;
        }

        $majorScore = 0;
        if (
            $student->major &&
            $job->required_major &&
            strtolower(trim($student->major)) === strtolower(trim($job->required_major))
        ) {
            $majorScore = 5;
        }

        $totalScore = round($skillScore + $locationScore + $typeScore + $majorScore);

        return [
            "match" => $totalScore,
            "matching_skills" => $matchingSkills,
            "missing_skills" => $missingSkills
        ];
    }
}