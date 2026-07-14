<?php

namespace App\Services;

use App\Models\JobPost;

class JobMatchingService
{

    public function getRecommendedJobs($student)
    {

      
        $studentSkills = $student->skills()
            ->pluck('skills.id')
            ->toArray();


       
        $jobs = JobPost::with([
            'company',
            'skills'
        ])
            ->where('status', 'Open')
            ->get();



        $recommendedJobs = $jobs->map(function ($job) use ($student, $studentSkills) {


            $score = 0;

            $reasons = [];


            $jobSkills = $job->skills
                ->pluck('id')
                ->toArray();



            $matchedSkillIds = array_intersect(
                $studentSkills,
                $jobSkills
            );



            $matchingSkills = $job->skills
                ->whereIn('id', $matchedSkillIds)
                ->pluck('name')
                ->values()
                ->toArray();



            $missingSkills = $job->skills
                ->whereNotIn('id', $studentSkills)
                ->pluck('name')
                ->values()
                ->toArray();



            if (count($jobSkills) > 0) {

                $skillScore =
                    (count($matchedSkillIds) / count($jobSkills)) * 80;


                $score += $skillScore;


                if (count($matchingSkills) > 0) {
                    $reasons[] =
                        "Matches your skills: "
                        . implode(", ", $matchingSkills);
                }

            }


            if (
                $student->location &&
                $job->location &&
                strtolower(trim($student->location)) === strtolower(trim($job->location))
            ) {
                $score += 10;

                $reasons[] = "Matches your preferred location";
            }




            if (
                $student->preferred_employment_type &&
                $job->employment_type &&
                strtolower($student->preferred_employment_type)
                ==
                strtolower($job->employment_type)
            ) {

                $score += 5;


                $reasons[] =
                    "Matches your preferred employment type";

            }





        
            if (
                $student->major &&
                $job->required_major &&
                strtolower($student->major)
                ==
                strtolower($job->required_major)
            ) {

                $score += 5;


                $reasons[] =
                    "Matches your major";

            }




            if ($score >= 90) {
                $level = "Excellent Match";
            } elseif ($score >= 75) {
                $level = "Good Match";
            } elseif ($score >= 50) {
                $level = "Fair Match";
            } else {
                $level = "Low Match";
            }





            return [

                "job_id" => $job->id,


                "title" => $job->title,


                "company" =>
                    $job->company->company_name ?? null,


                "location" =>
                    $job->location,


                "salary" =>
                    $job->salary,


                "employment_type" =>
                    $job->employment_type,


                "work_mode" =>
                    $job->work_mode,



                "match" =>
                    round($score),



                "recommendation_level" =>
                    $level,



                "matching_skills" =>
                    $matchingSkills,



                "missing_skills" =>
                    $missingSkills,



                "reasons" =>
                    $reasons

            ];

        });



        // ترتيب الوظائف من الأعلى Match للأقل
        return $recommendedJobs
            ->sortByDesc('match')
            ->values();

    }

}