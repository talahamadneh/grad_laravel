<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\WithoutMiddleware;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CompanyJobDescriptionGeneratorTest extends TestCase
{
    use WithoutMiddleware;

    public function test_empty_description_generates_from_structured_job_data(): void
    {
        config([
            'services.groq.keys' => ['test-groq-key'],
            'services.groq.model' => 'test-model',
        ]);

        $capturedPrompt = null;

        Http::fake([
            'https://api.groq.com/*' => function ($request) use (&$capturedPrompt) {
                $capturedPrompt = $request->data()['messages'][1]['content'] ?? '';

                return Http::response([
                    'choices' => [
                        [
                            'message' => [
                                'content' => 'Generated backend developer description.',
                            ],
                        ],
                    ],
                ], 200);
            },
        ]);

        $response = $this->postJson('/api/company/jobs/generate-description', [
            'title' => 'Junior Backend Developer',
            'department' => 'Engineering',
            'level' => 'Junior',
            'work_mode' => 'Hybrid',
            'description' => '',
            'skills' => ['Laravel', 'PHP', 'MySQL'],
        ]);

        $response->assertOk()
            ->assertJson([
                'description' => 'Generated backend developer description.',
            ]);

        $this->assertStringContainsString('Write a professional and engaging job description', $capturedPrompt);
        $this->assertStringContainsString('Job Title: Junior Backend Developer', $capturedPrompt);
        $this->assertStringContainsString('Department: Engineering', $capturedPrompt);
        $this->assertStringContainsString('Required Skills: Laravel, PHP, MySQL', $capturedPrompt);
        $this->assertStringNotContainsString('Existing description:', $capturedPrompt);
    }

    public function test_existing_description_is_improved_with_job_data_only_as_context(): void
    {
        config([
            'services.groq.keys' => ['test-groq-key'],
            'services.groq.model' => 'test-model',
        ]);

        $capturedPrompt = null;

        Http::fake([
            'https://api.groq.com/*' => function ($request) use (&$capturedPrompt) {
                $capturedPrompt = $request->data()['messages'][1]['content'] ?? '';

                return Http::response([
                    'choices' => [
                        [
                            'message' => [
                                'content' => 'Improved backend developer description.',
                            ],
                        ],
                    ],
                ], 200);
            },
        ]);

        $existingDescription = 'Build APIs for the student career platform using Laravel.';

        $response = $this->postJson('/api/company/jobs/generate-description', [
            'title' => 'Junior Backend Developer',
            'department' => 'Engineering',
            'level' => 'Junior',
            'work_mode' => 'Hybrid',
            'description' => $existingDescription,
            'skills' => ['Laravel', 'PHP', 'MySQL'],
        ]);

        $response->assertOk()
            ->assertJson([
                'description' => 'Improved backend developer description.',
            ]);

        $this->assertStringContainsString('Improve and rewrite the existing job description professionally', $capturedPrompt);
        $this->assertStringContainsString('Use the structured job data only as context', $capturedPrompt);
        $this->assertStringContainsString('Do not invent requirements, benefits, responsibilities, salary, technologies, company information', $capturedPrompt);
        $this->assertStringContainsString('Job Title: Junior Backend Developer', $capturedPrompt);
        $this->assertStringContainsString('Existing description:', $capturedPrompt);
        $this->assertStringContainsString($existingDescription, $capturedPrompt);
    }
}
