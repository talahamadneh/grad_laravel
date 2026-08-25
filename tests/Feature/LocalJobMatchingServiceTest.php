<?php

namespace Tests\Feature;

use App\Models\Education;
use App\Models\JobPost;
use App\Models\Resume;
use App\Models\Skill;
use App\Models\Student;
use App\Services\LocalJobMatchingService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class LocalJobMatchingServiceTest extends TestCase
{
    public function test_payload_building_uses_minimal_professional_data(): void
    {
        $service = new LocalJobMatchingService();

        $payload = $service->payload(
            $this->student(),
            $this->job(),
            $this->resume()
        );

        $this->assertSame('Computer Science', $payload['student']['major']);
        $this->assertSame('Amman', $payload['student']['location']);
        $this->assertSame('Full-Time', $payload['student']['preferred_employment_type']);
        $this->assertSame(['PHP'], $payload['student']['skills']);
        $this->assertSame('1.5', $payload['student']['resume']['total_years_experience']);
        $this->assertSame(['Laravel', 'PHP'], $payload['job']['skills']);
        $this->assertSame('1.0', $payload['job']['min_experience_years']);
        $this->assertSame('3.0', $payload['job']['max_experience_years']);

        $encoded = json_encode($payload);
        $this->assertStringNotContainsString('student@example.com', $encoded);
        $this->assertStringNotContainsString('0790000000', $encoded);
        $this->assertArrayNotHasKey('id', $payload['student']);
        $this->assertArrayNotHasKey('id', $payload['job']);
    }

    public function test_successful_response_handling(): void
    {
        config(['services.local_job_matcher.url' => 'http://127.0.0.1:8001']);

        Http::fake([
            '127.0.0.1:8001/match-job' => Http::response($this->matcherResult(), 200),
        ]);

        $result = (new LocalJobMatchingService())->match(
            $this->student(),
            $this->job(),
            $this->resume()
        );

        $this->assertSame(82, $result['score']);
        $this->assertSame('Good Match', $result['level']);
        $this->assertSame(['laravel'], $result['matching_skills']);

        Http::assertSent(function ($request) {
            return $request->url() === 'http://127.0.0.1:8001/match-job'
                && isset($request['student'], $request['job'])
                && !isset($request['student']['name'], $request['student']['email'], $request['student']['phone']);
        });
    }

    public function test_timeout_or_unavailable_service_fails_cleanly(): void
    {
        config(['services.local_job_matcher.url' => 'http://127.0.0.1:8001']);

        Http::fake(function () {
            throw new ConnectionException('Connection timed out');
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Local job matching service is temporarily unavailable.');

        (new LocalJobMatchingService())->match(
            $this->student(),
            $this->job(),
            $this->resume()
        );
    }

    public function test_malformed_python_response_fails_cleanly(): void
    {
        config(['services.local_job_matcher.url' => 'http://127.0.0.1:8001']);

        Http::fake([
            '127.0.0.1:8001/match-job' => Http::response(['score' => 101], 200),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Local job matching service returned an invalid response.');

        (new LocalJobMatchingService())->match(
            $this->student(),
            $this->job(),
            $this->resume()
        );
    }

    public function test_service_does_not_call_groq_or_gemini(): void
    {
        config(['services.local_job_matcher.url' => 'http://127.0.0.1:8001']);

        Http::fake([
            '127.0.0.1:8001/match-job' => Http::response($this->matcherResult(), 200),
            'api.groq.com/*' => Http::response(['unexpected' => true], 500),
            'generativelanguage.googleapis.com/*' => Http::response(['unexpected' => true], 500),
        ]);

        (new LocalJobMatchingService())->match(
            $this->student(),
            $this->job(),
            $this->resume()
        );

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->url() === 'http://127.0.0.1:8001/match-job');
    }

    private function student(): Student
    {
        $student = new Student([
            'major' => 'Computer Science',
            'location' => 'Amman',
            'preferred_employment_type' => 'Full-Time',
            'headline' => 'Backend Developer',
            'bio' => 'Laravel backend developer.',
            'phone' => '0790000000',
        ]);
        $student->id = 10;

        $student->setRelation('skills', new Collection([
            new Skill(['name' => 'PHP']),
        ]));

        $student->setRelation('education', new Collection([
            new Education([
                'degree' => 'BS Computer Science',
                'major' => 'Computer Science',
                'university' => 'Example University',
            ]),
        ]));

        return $student;
    }

    private function resume(): Resume
    {
        return new Resume([
            'professional_title' => 'Backend Developer',
            'summary' => 'Builds Laravel APIs.',
            'total_years_experience' => 1.5,
            'skills' => [['name' => 'Laravel']],
            'education' => [['field_of_study' => 'Computer Science']],
            'experience' => [['title' => 'Intern', 'description' => 'Built REST APIs.']],
            'projects' => [['name' => 'Career Platform', 'description' => 'Laravel and MySQL job platform.']],
        ]);
    }

    private function job(): JobPost
    {
        $job = new JobPost([
            'title' => 'Backend Developer',
            'department' => 'Engineering',
            'description' => 'Build Laravel APIs.',
            'responsibilities' => 'Develop backend services.',
            'requirements' => 'Laravel and PHP.',
            'required_major' => 'Computer Science',
            'employment_type' => 'Full-Time',
            'level' => 'Junior',
            'work_mode' => 'On-site',
            'location' => 'Amman',
            'min_experience_years' => 1,
            'max_experience_years' => 3,
        ]);
        $job->id = 14;

        $job->setRelation('skills', new Collection([
            new Skill(['name' => 'Laravel']),
            new Skill(['name' => 'PHP']),
        ]));

        return $job;
    }

    private function matcherResult(): array
    {
        return [
            'score' => 82,
            'level' => 'Good Match',
            'breakdown' => [
                'skills' => ['score' => 40, 'max_weight' => 45, 'applicable' => true],
            ],
            'matching_skills' => ['laravel'],
            'missing_skills' => ['php'],
            'reasons' => ['Matched required skills: laravel'],
            'warnings' => [],
        ];
    }
}
