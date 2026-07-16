<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Skill;
use App\Models\JobPost;

class SkillSeeder extends Seeder
{
    public function run(): void
    {
        $skills = [
            'PHP',
            'Laravel',
            'MySQL',
            'REST API',
            'React',
            'TypeScript',
            'JavaScript',
            'HTML',
            'CSS',
            'React Native',
            'Mobile Development',
            'Figma',
            'UX Design',
        ];


        foreach ($skills as $skill) {
            Skill::create([
                'name' => $skill
            ]);
        }


        $backend = JobPost::where('title', 'Laravel Backend Developer')->first();

        $backend->skills()->attach([
            Skill::where('name','PHP')->first()->id,
            Skill::where('name','Laravel')->first()->id,
            Skill::where('name','MySQL')->first()->id,
            Skill::where('name','REST API')->first()->id,
        ]);



        $frontend = JobPost::where('title', 'React Frontend Developer')->first();

        $frontend->skills()->attach([
            Skill::where('name','React')->first()->id,
            Skill::where('name','TypeScript')->first()->id,
            Skill::where('name','JavaScript')->first()->id,
            Skill::where('name','HTML')->first()->id,
            Skill::where('name','CSS')->first()->id,
        ]);



        $mobile = JobPost::where('title', 'Mobile Developer Contract')->first();

        $mobile->skills()->attach([
            Skill::where('name','React Native')->first()->id,
            Skill::where('name','JavaScript')->first()->id,
            Skill::where('name','Mobile Development')->first()->id,
        ]);



        $designer = JobPost::where('title', 'UI/UX Designer')->first();

        $designer->skills()->attach([
            Skill::where('name','Figma')->first()->id,
            Skill::where('name','UX Design')->first()->id,
        ]);
    }
}