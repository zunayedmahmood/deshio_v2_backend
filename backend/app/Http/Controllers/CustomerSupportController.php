<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Mail;

class CustomerSupportController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:customer');
    }

    /**
     * Get all support tickets for customer
     */
    public function getTickets(Request $request): JsonResponse
    {
        try {
            $customerId = auth('customer')->id();
            $status = $request->query('status');
            $priority = $request->query('priority');
            $perPage = $request->query('per_page', 10);

            // Simulate support tickets
            $tickets = $this->simulateCustomerTickets($customerId, $status, $priority);
            
            $paginated = array_slice($tickets, 0, $perPage);

            return response()->json([
                'success' => true,
                'data' => [
                    'tickets' => $paginated,
                    'pagination' => [
                        'current_page' => 1,
                        'total_pages' => ceil(count($tickets) / $perPage),
                        'per_page' => $perPage,
                        'total' => count($tickets),
                    ],
                    'summary' => [
                        'open_tickets' => count(array_filter($tickets, fn($t) => $t['status'] === 'open')),
                        'pending_tickets' => count(array_filter($tickets, fn($t) => $t['status'] === 'pending')),
                        'resolved_tickets' => count(array_filter($tickets, fn($t) => $t['status'] === 'resolved')),
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch support tickets',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get specific support ticket
     */
    public function getTicket($ticketId): JsonResponse
    {
        try {
            $customerId = auth('customer')->id();
            
            $ticket = $this->simulateSingleTicket($ticketId, $customerId);
            
            if (!$ticket) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ticket not found',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'ticket' => $ticket,
                    'messages' => $this->simulateTicketMessages($ticketId),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch ticket details',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Create new support ticket
     */
    public function createTicket(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'subject' => 'required|string|max:255',
                'category' => 'required|string|in:order,payment,product,delivery,account,refund,technical,other',
                'priority' => 'required|string|in:low,medium,high,urgent',
                'description' => 'required|string|max:2000',
                'order_number' => 'nullable|string',
                'attachments' => 'nullable|array',
                'attachments.*' => 'file|max:10240', // 10MB max per file
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $customerId = auth('customer')->id();
            
            // Generate ticket ID
            $ticketId = 'TKT-' . date('ymd') . '-' . str_pad(random_int(1, 9999), 4, '0', STR_PAD_LEFT);

            // Handle file attachments
            $attachmentPaths = [];
            if ($request->hasFile('attachments')) {
                foreach ($request->file('attachments') as $file) {
                    $path = $file->store('support-attachments', 'public');
                    $attachmentPaths[] = [
                        'filename' => $file->getClientOriginalName(),
                        'path' => $path,
                        'size' => $file->getSize(),
                        'mime_type' => $file->getMimeType(),
                    ];
                }
            }

            // In real app, you'd save to database
            $ticket = [
                'ticket_id' => $ticketId,
                'customer_id' => $customerId,
                'subject' => $request->subject,
                'category' => $request->category,
                'priority' => $request->priority,
                'description' => $request->description,
                'order_number' => $request->order_number,
                'status' => 'open',
                'attachments' => $attachmentPaths,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            // Send confirmation email
            $this->sendTicketCreationEmail($ticket);

            return response()->json([
                'success' => true,
                'message' => 'Support ticket created successfully',
                'data' => [
                    'ticket' => $ticket,
                    'estimated_response_time' => $this->getEstimatedResponseTime($request->priority),
                ],
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create support ticket',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Add message to ticket
     */
    public function addMessage(Request $request, $ticketId): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'message' => 'required|string|max:2000',
                'attachments' => 'nullable|array',
                'attachments.*' => 'file|max:10240',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $customerId = auth('customer')->id();

            // Verify ticket belongs to customer
            if (!$this->verifyTicketOwnership($ticketId, $customerId)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ticket not found',
                ], 404);
            }

            // Handle attachments
            $attachmentPaths = [];
            if ($request->hasFile('attachments')) {
                foreach ($request->file('attachments') as $file) {
                    $path = $file->store('support-attachments', 'public');
                    $attachmentPaths[] = [
                        'filename' => $file->getClientOriginalName(),
                        'path' => $path,
                        'size' => $file->getSize(),
                        'mime_type' => $file->getMimeType(),
                    ];
                }
            }

            $message = [
                'id' => uniqid(),
                'ticket_id' => $ticketId,
                'sender_type' => 'customer',
                'sender_id' => $customerId,
                'message' => $request->message,
                'attachments' => $attachmentPaths,
                'created_at' => now(),
            ];

            return response()->json([
                'success' => true,
                'message' => 'Message added successfully',
                'data' => ['message' => $message],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to add message',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Close support ticket
     */
    public function closeTicket($ticketId): JsonResponse
    {
        try {
            $customerId = auth('customer')->id();

            if (!$this->verifyTicketOwnership($ticketId, $customerId)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ticket not found',
                ], 404);
            }

            // In real app, you'd update the database
            // For demo, we'll just return success

            return response()->json([
                'success' => true,
                'message' => 'Ticket closed successfully',
                'data' => [
                    'ticket_id' => $ticketId,
                    'status' => 'closed',
                    'closed_at' => now(),
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to close ticket',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Rate support experience
     */
    public function rateSupport(Request $request, $ticketId): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'rating' => 'required|integer|min:1|max:5',
                'feedback' => 'nullable|string|max:1000',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $customerId = auth('customer')->id();

            if (!$this->verifyTicketOwnership($ticketId, $customerId)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ticket not found',
                ], 404);
            }

            $rating = [
                'ticket_id' => $ticketId,
                'customer_id' => $customerId,
                'rating' => $request->rating,
                'feedback' => $request->feedback,
                'created_at' => now(),
            ];

            return response()->json([
                'success' => true,
                'message' => 'Thank you for your feedback',
                'data' => ['rating' => $rating],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to submit rating',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get FAQ items
     */
    public function getFAQ(Request $request): JsonResponse
    {
        try {
            $category = $request->query('category');
            $search = $request->query('search');

            $faqs = $this->getFAQData($category, $search);

            return response()->json([
                'success' => true,
                'data' => [
                    'faqs' => $faqs,
                    'categories' => $this->getFAQCategories(),
                    'total' => count($faqs),
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch FAQ',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get support statistics
     */
    public function getSupportStats(): JsonResponse
    {
        try {
            $customerId = auth('customer')->id();

            $stats = [
                'total_tickets' => 5,
                'open_tickets' => 2,
                'resolved_tickets' => 3,
                'average_response_time' => '2 hours',
                'satisfaction_rating' => 4.5,
                'last_ticket_date' => now()->subDays(3),
            ];

            return response()->json([
                'success' => true,
                'data' => ['statistics' => $stats],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch support statistics',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Initiate live chat
     */
    public function initiateLiveChat(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'initial_message' => 'required|string|max:500',
                'category' => 'required|string|in:order,payment,product,delivery,account,technical,other',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $customerId = auth('customer')->id();
            $chatId = 'chat_' . uniqid();

            $chatSession = [
                'chat_id' => $chatId,
                'customer_id' => $customerId,
                'category' => $request->category,
                'status' => 'waiting',
                'queue_position' => rand(1, 5),
                'estimated_wait_time' => rand(2, 10) . ' minutes',
                'initial_message' => $request->initial_message,
                'created_at' => now(),
            ];

            return response()->json([
                'success' => true,
                'message' => 'Live chat session initiated',
                'data' => [
                    'chat_session' => $chatSession,
                    'support_hours' => '9 AM - 9 PM (Daily)',
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to initiate live chat',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    // Private helper methods

    private function simulateCustomerTickets(int $customerId, ?string $status, ?string $priority): array
    {
        $allTickets = [
            [
                'ticket_id' => 'TKT-241118-0001',
                'subject' => 'Order not delivered',
                'category' => 'delivery',
                'priority' => 'high',
                'status' => 'open',
                'created_at' => now()->subHours(2),
                'updated_at' => now()->subHour(),
                'order_number' => 'ORD-241118-1234',
            ],
            [
                'ticket_id' => 'TKT-241117-0002',
                'subject' => 'Payment issue',
                'category' => 'payment',
                'priority' => 'medium',
                'status' => 'pending',
                'created_at' => now()->subDay(),
                'updated_at' => now()->subHours(3),
                'order_number' => null,
            ],
            [
                'ticket_id' => 'TKT-241116-0003',
                'subject' => 'Product defective',
                'category' => 'product',
                'priority' => 'low',
                'status' => 'resolved',
                'created_at' => now()->subDays(2),
                'updated_at' => now()->subDays(1),
                'order_number' => 'ORD-241115-5678',
            ],
        ];

        $filtered = $allTickets;

        if ($status) {
            $filtered = array_filter($filtered, fn($ticket) => $ticket['status'] === $status);
        }

        if ($priority) {
            $filtered = array_filter($filtered, fn($ticket) => $ticket['priority'] === $priority);
        }

        return array_values($filtered);
    }

    private function simulateSingleTicket(string $ticketId, int $customerId): ?array
    {
        $tickets = $this->simulateCustomerTickets($customerId, null, null);
        
        foreach ($tickets as $ticket) {
            if ($ticket['ticket_id'] === $ticketId) {
                return $ticket;
            }
        }

        return null;
    }

    private function simulateTicketMessages(string $ticketId): array
    {
        return [
            [
                'id' => 'msg_1',
                'ticket_id' => $ticketId,
                'sender_type' => 'customer',
                'sender_name' => 'You',
                'message' => 'My order has not been delivered yet and it is already past the expected delivery date.',
                'created_at' => now()->subHours(2),
                'attachments' => [],
            ],
            [
                'id' => 'msg_2',
                'ticket_id' => $ticketId,
                'sender_type' => 'agent',
                'sender_name' => 'Support Agent - Sarah',
                'message' => 'I apologize for the delay. Let me check the status of your order and get back to you with an update.',
                'created_at' => now()->subHours(1),
                'attachments' => [],
            ],
            [
                'id' => 'msg_3',
                'ticket_id' => $ticketId,
                'sender_type' => 'agent',
                'sender_name' => 'Support Agent - Sarah',
                'message' => 'I have contacted our delivery partner. Your order will be delivered today before 6 PM. You will receive tracking updates shortly.',
                'created_at' => now()->subMinutes(30),
                'attachments' => [],
            ],
        ];
    }

    private function verifyTicketOwnership(string $ticketId, int $customerId): bool
    {
        // In real app, check database
        return str_contains($ticketId, 'TKT-');
    }

    private function getEstimatedResponseTime(string $priority): string
    {
        $responseTimes = [
            'urgent' => '1 hour',
            'high' => '2-4 hours',
            'medium' => '4-8 hours',
            'low' => '1-2 business days',
        ];

        return $responseTimes[$priority] ?? '1-2 business days';
    }

    private function sendTicketCreationEmail(array $ticket): void
    {
        // In real app, send email notification
        // Mail::to(auth('customer')->user()->email)->send(new TicketCreated($ticket));
    }

    private function getFAQData(?string $category, ?string $search): array
    {
        $faqs = [
            [
                'id' => 1,
                'category' => 'order',
                'question' => 'How can I track my order?',
                'answer' => 'You can track your order by going to the "My Orders" section and clicking on the track button next to your order.',
                'helpful_count' => 25,
            ],
            [
                'id' => 2,
                'category' => 'delivery',
                'question' => 'What are the delivery charges?',
                'answer' => 'Delivery charges are ৳60 for Dhaka and ৳120 for other major cities. Free delivery for orders above ৳1500.',
                'helpful_count' => 18,
            ],
            [
                'id' => 3,
                'category' => 'payment',
                'question' => 'What payment methods do you accept?',
                'answer' => 'We accept Cash on Delivery, bKash, Nagad, Rocket, Credit/Debit Cards, and Bank Transfer.',
                'helpful_count' => 32,
            ],
            [
                'id' => 4,
                'category' => 'refund',
                'question' => 'How do I return a product?',
                'answer' => 'You can return products within 7 days of delivery. Go to your order details and click "Return Item".',
                'helpful_count' => 15,
            ],
        ];

        if ($category) {
            $faqs = array_filter($faqs, fn($faq) => $faq['category'] === $category);
        }

        if ($search) {
            $faqs = array_filter($faqs, fn($faq) => 
                stripos($faq['question'], $search) !== false || 
                stripos($faq['answer'], $search) !== false
            );
        }

        return array_values($faqs);
    }

    private function getFAQCategories(): array
    {
        return [
            ['id' => 'order', 'name' => 'Orders & Tracking'],
            ['id' => 'delivery', 'name' => 'Delivery & Shipping'],
            ['id' => 'payment', 'name' => 'Payment & Billing'],
            ['id' => 'refund', 'name' => 'Returns & Refunds'],
            ['id' => 'account', 'name' => 'Account Management'],
            ['id' => 'product', 'name' => 'Products & Inventory'],
        ];
    }
}