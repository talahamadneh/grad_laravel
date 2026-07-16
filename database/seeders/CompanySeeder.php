<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Company;

class CompanySeeder extends Seeder
{
    public function run(): void
    {
        Company::create([
            'user_id' => 1,
            'company_name' => 'Tech Solutions',
            'industry' => 'Technology',
            'description' => 'Software development company',
            'approval_status' => 'Approved',
        ]);

        Company::create([
            'user_id' => 2,
            'company_name' => 'Google',
            'industry' => 'Technology',
            'description' => 'Global technology company',
            'approval_status' => 'Approved',
        ]);

        Company::create([
            'user_id' => 3,
            'company_name' => 'Microsoft',
            'industry' => 'Software',
            'description' => 'Software and cloud services',
            'approval_status' => 'Approved',
        ]);

        Company::create([
            'user_id' => 4,
            'company_name' => 'Amazon',
            'industry' => 'E-commerce',
            'description' => 'Cloud services company',
            'approval_status' => 'Approved',
        ]);

        Company::create([
            'user_id' => 5,
            'company_name' => 'Meta',
            'industry' => 'Social Media',
            'description' => 'AI and social platforms',
            'approval_status' => 'Approved',
        ]);

        Company::create([
            'user_id' => 4,
            'company_name' => 'Nvidia',
            'industry' => 'AI Hardware',
            'description' => 'GPU and AI technologies',
            'approval_status' => 'Approved',
        ]);

        Company::create([
            'user_id' => 5,
            'company_name' => 'Apple',
            'industry' => 'Technology',
            'description' => 'Consumer electronics company',
            'approval_status' => 'Approved',
        ]);
    }
}