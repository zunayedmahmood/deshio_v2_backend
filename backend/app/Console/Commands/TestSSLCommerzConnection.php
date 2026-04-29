<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Raziul\Sslcommerz\Facades\Sslcommerz;

class TestSSLCommerzConnection extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sslcommerz:test';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test SSLCommerz payment gateway connection';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Testing SSLCommerz Payment Gateway Connection...');
        $this->newLine();

        // Step 1: Check Configuration
        $this->info('Step 1: Checking Configuration');
        $storeId = config('sslcommerz.store.id');
        $storePassword = config('sslcommerz.store.password');
        $sandbox = config('sslcommerz.sandbox');
        $currency = config('sslcommerz.store.currency');

        if (empty($storeId) || empty($storePassword)) {
            $this->error('✗ SSLCommerz credentials not configured!');
            $this->warn('Please set SSLC_STORE_ID and SSLC_STORE_PASSWORD in your .env file');
            return 1;
        }

        $this->info("✓ Store ID: {$storeId}");
        $this->info("✓ Mode: " . ($sandbox ? 'Sandbox' : 'Production'));
        $this->info("✓ Currency: {$currency}");
        $this->newLine();

        // Step 2: Test Payment Session Creation
        $this->info('Step 2: Testing Payment Session Creation (Validation)');
        
        try {
            // Create a test payment session using the same method as production code
            $transactionId = 'TEST_' . uniqid();
            $testAmount = 100;
            
            $response = Sslcommerz::setOrder($testAmount, $transactionId, 'Test Order - Connection Validation')
                ->setCustomer('Test Customer', 'test@example.com', '01700000000')
                ->setShippingInfo(1, 'Test Address, Dhaka, Bangladesh')
                ->makePayment(['test' => true]); // Pass test flag

            if ($response->success()) {
                $this->info('✓ Payment session created successfully!');
                $this->info("  Gateway URL: " . $response->gatewayPageURL());
                $this->info("  Transaction ID: {$transactionId}");
                $this->newLine();
                
                $this->info('Store Information:');
                $this->info("  Store ID: {$storeId}");
                $this->info("  Mode: " . ($sandbox ? 'Sandbox (Test)' : 'Production (Live)'));
                $this->newLine();
                
                $this->info('✓ SSLCommerz connection test completed successfully!');
                $this->info('✓ Payment gateway is ready to use.');
                $this->newLine();
                $this->warn('Note: A test payment session was created but not completed.');
                $this->warn('No actual transaction was processed or charged.');
                return 0;
            } else {
                $this->error('✗ Payment session creation failed!');
                $this->error('Reason: ' . $response->failedReason());
                $this->newLine();
                $this->warn('Troubleshooting:');
                $this->warn('1. Verify your store credentials are correct in .env file');
                $this->warn('2. Check if your IP is whitelisted in SSLCommerz merchant panel');
                $this->warn('3. Ensure your store is active and approved by SSLCommerz');
                $this->warn('4. Verify SSLC_SANDBOX setting matches your credentials (sandbox/live)');
                return 1;
            }
            
        } catch (\Exception $e) {
            $this->error('✗ Error testing SSLCommerz connection!');
            $this->error('Error: ' . $e->getMessage());
            $this->newLine();
            $this->warn('Troubleshooting:');
            $this->warn('1. Verify your store credentials are correct');
            $this->warn('2. Check if your IP is whitelisted in SSLCommerz merchant panel');
            $this->warn('3. Ensure your store is active and approved');
            $this->warn('4. Check network connectivity to SSLCommerz servers');
            $this->warn('5. Run: php artisan config:clear to clear cached config');
            return 1;
        }
    }
}
