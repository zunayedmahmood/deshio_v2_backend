<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Codeboxr\PathaoCourier\Facade\PathaoCourier;
use Illuminate\Support\Facades\Http;

class TestPathaoConnection extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'pathao:test';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test Pathao API connection and authentication';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('=== Pathao API Connection Test ===');
        $this->newLine();

        // 1. Check configuration
        $this->info('1. Checking configuration...');
        $clientId = config('pathao.client_id');
        $clientSecret = config('pathao.client_secret');
        $username = config('pathao.username');
        $password = config('pathao.password');
        $baseUrl = config('pathao.base_url', 'https://api-hermes.pathao.com');
        $sandbox = config('pathao.sandbox', false);

        $this->line("   Base URL: {$baseUrl}");
        $this->line("   Sandbox Mode: " . ($sandbox ? 'Yes' : 'No'));
        $this->line("   Client ID: " . ($clientId ? '✓ Set' : '✗ Missing'));
        $this->line("   Client Secret: " . ($clientSecret ? '✓ Set' : '✗ Missing'));
        $this->line("   Username: " . ($username ? '✓ Set' : '✗ Missing - REQUIRED!'));
        $this->line("   Password: " . ($password ? '✓ Set' : '✗ Missing - REQUIRED!'));
        $this->newLine();

        if (!$clientId || !$clientSecret) {
            $this->error('❌ Client ID and Client Secret are required!');
            $this->info('Add these to your .env file:');
            $this->line('PATHAO_CLIENT_ID=ELe3QM9b69');
            $this->line('PATHAO_CLIENT_SECRET=34wMViuF691Ms80C2nWT8ofaTDpKmo7ZABME4EmH');
            return Command::FAILURE;
        }

        if (!$username || !$password) {
            $this->error('❌ Username and Password are required for Pathao API!');
            $this->newLine();
            $this->warn('Pathao uses OAuth2 Password Grant authentication.');
            $this->warn('You need to get your Pathao merchant account credentials.');
            $this->newLine();
            $this->info('Steps to get credentials:');
            $this->line('1. Login to your Pathao merchant portal');
            $this->line('2. Go to Settings > API Settings');
            $this->line('3. Copy your Username and Password');
            $this->line('4. Add to .env file:');
            $this->line('   PATHAO_USERNAME=your_merchant_username');
            $this->line('   PATHAO_PASSWORD=your_merchant_password');
            return Command::FAILURE;
        }

        // 2. Test authentication
        $this->info('2. Testing authentication...');
        try {
            $response = Http::timeout(30)->post("{$baseUrl}/aladdin/api/v1/issue-token", [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'username' => $username,
                'password' => $password,
                'grant_type' => 'password',
            ]);

            if ($response->successful()) {
                $data = $response->json();
                $this->info('   ✓ Authentication successful!');
                $this->line('   Access Token: ' . substr($data['access_token'] ?? '', 0, 20) . '...');
                $this->line('   Token Type: ' . ($data['token_type'] ?? 'N/A'));
                $this->line('   Expires In: ' . ($data['expires_in'] ?? 'N/A') . ' seconds');
            } else {
                $this->error('   ✗ Authentication failed!');
                $this->line('   Status: ' . $response->status());
                $this->line('   Response: ' . $response->body());
                return Command::FAILURE;
            }
        } catch (\Exception $e) {
            $this->error('   ✗ Exception: ' . $e->getMessage());
            return Command::FAILURE;
        }
        $this->newLine();

        // 3. Test city list endpoint
        $this->info('3. Testing city list endpoint...');
        try {
            $result = PathaoCourier::area()->city();
            
            // Convert stdClass to array if needed
            $resultArray = json_decode(json_encode($result), true);
            
            // Check both possible response formats
            if (isset($resultArray['data']) && is_array($resultArray['data'])) {
                $cities = $resultArray['data'];
                $this->info('   ✓ Successfully fetched ' . count($cities) . ' cities!');
                
                if (count($cities) > 0) {
                    $this->line('   First 5 cities:');
                    foreach (array_slice($cities, 0, 5) as $city) {
                        $this->line('   - ' . ($city['city_name'] ?? 'Unknown') . ' (ID: ' . ($city['city_id'] ?? 'N/A') . ')');
                    }
                }
            } else {
                $this->warn('   ? Unexpected response format');
                $this->line('   Response: ' . json_encode($resultArray));
            }
        } catch (\Exception $e) {
            $this->error('   ✗ Exception: ' . $e->getMessage());
            return Command::FAILURE;
        }
        $this->newLine();

        // 4. Test store list endpoint
        $this->info('4. Testing store list endpoint...');
        try {
            $result = PathaoCourier::store()->list();
            
            // Convert stdClass to array if needed
            $resultArray = json_decode(json_encode($result), true);
            
            if (isset($resultArray['data']) && is_array($resultArray['data'])) {
                $stores = $resultArray['data'];
                $this->info('   ✓ Successfully fetched ' . count($stores) . ' stores!');
                
                if (count($stores) > 0) {
                    $this->line('   Your stores:');
                    foreach ($stores as $store) {
                        $this->line('   - ' . ($store['store_name'] ?? 'Unknown') . ' (ID: ' . ($store['store_id'] ?? 'N/A') . ')');
                    }
                    $this->newLine();
                    $this->info('   💡 Tip: Add a store ID to your .env file:');
                    $this->line('   PATHAO_STORE_ID=' . ($stores[0]['store_id'] ?? ''));
                } else {
                    $this->warn('   ⚠ No stores found. You need to create a store first!');
                    $this->line('   Use: POST /api/shipments/pathao/stores');
                }
            } else {
                $this->warn('   ? Unexpected response format');
                $this->line('   Response: ' . json_encode($resultArray));
            }
        } catch (\Exception $e) {
            $this->error('   ✗ Exception: ' . $e->getMessage());
        }
        $this->newLine();

        $this->info('=== Test Complete ===');
        $this->info('✓ Pathao API connection is working!');
        $this->info('You can now use the API endpoints.');
        
        return Command::SUCCESS;
    }
}

