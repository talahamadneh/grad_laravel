<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\JobPost;

class JobPostSeeder extends Seeder
{
    public function run(): void
    {
        JobPost::insert([

            [
                'company_id' => 9,
                'title' => 'Laravel Backend Developer',
                'description' => 'Develop Laravel APIs and backend systems.',
                'about' => 'We need a backend developer to build secure and scalable Laravel applications.',
                'responsibilities' => 'Create REST APIs, manage databases, write clean backend code.',
                'requirements' => 'Strong PHP and Laravel knowledge, MySQL experience, API development skills.',
                'salary' => 1500,
                'employment_type' => 'Full-Time',
                'work_mode' => 'On-site',
                'location' => 'Amman, Jordan',
                'deadline' => now()->addDays(30),
                'vacancies' => 2,
                'status' => 'Open',
                'created_at' => now(),
                'updated_at' => now(),
            ],

            [
                'company_id' => 11,
                'title' => 'React Frontend Developer',
                'description' => 'Build modern React web applications.',
                'about' => 'Join our frontend team to create modern user interfaces.',
                'responsibilities' => 'Develop React components, improve UI performance, collaborate with designers.',
                'requirements' => 'Experience with React, TypeScript, JavaScript and frontend development.',
                'salary' => 1200,
                'employment_type' => 'Part-Time',
                'work_mode' => 'Remote',
                'location' => 'Remote',
                'deadline' => now()->addDays(20),
                'vacancies' => 1,
                'status' => 'Open',
                'created_at' => now(),
                'updated_at' => now(),
            ],

            [
                'company_id' => 12,
                'title' => 'Software Engineering Intern',
                'description' => 'Internship opportunity for software engineering students.',
                'about' => 'Training opportunity for students to gain practical software experience.',
                'responsibilities' => 'Assist development team, test applications, learn software practices.',
                'requirements' => 'Software engineering student, basic programming knowledge.',
                'salary' => 500,
                'employment_type' => 'Internship',
                'work_mode' => 'Hybrid',
                'location' => 'Nablus',
                'deadline' => now()->addDays(15),
                'vacancies' => 3,
                'status' => 'Open',
                'created_at' => now(),
                'updated_at' => now(),
            ],

            [
                'company_id' => 13,
                'title' => 'Mobile Developer Contract',
                'description' => 'Develop React Native mobile applications.',
                'about' => 'Looking for a mobile developer to build cross-platform applications.',
                'responsibilities' => 'Develop mobile apps, fix bugs, optimize performance.',
                'requirements' => 'React Native, JavaScript, mobile development experience.',
                'salary' => 2000,
                'employment_type' => 'Contract',
                'work_mode' => 'Remote',
                'location' => 'Remote',
                'deadline' => now()->addDays(45),
                'vacancies' => 1,
                'status' => 'Open',
                'created_at' => now(),
                'updated_at' => now(),
            ],

            [
                'company_id' => 6,
                'title' => 'UI/UX Designer',
                'description' => 'Design user interfaces and experiences.',
                'about' => 'Create attractive and user-friendly designs.',
                'responsibilities' => 'Design wireframes, prototypes and improve user experience.',
                'requirements' => 'Figma skills, UX principles, design experience.',
                'salary' => 1000,
                'employment_type' => 'Full-Time',
                'work_mode' => 'Hybrid',
                'location' => 'Ramallah',
                'deadline' => now()->addDays(25),
                'vacancies' => 1,
                'status' => 'Open',
                'created_at' => now(),
                'updated_at' => now(),
            ],

        ]);
    }
}