<?php

namespace App\Http\Controllers;

use App\Models\ContactMessage;
use App\Traits\DatabaseAgnosticSearch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class ContactMessageController extends Controller
{
    use DatabaseAgnosticSearch;

    /**
     * Display a listing of contact messages with filters, search, sorting, and pagination
     */
    public function index(Request $request)
    {
        $query = ContactMessage::with(['repliedBy']);

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Search by name, phone, or message
        if ($request->has('search')) {
            $search = $request->search;
            $this->whereAnyLike($query, ['name', 'phone', 'message'], $search);
        }

        // Date range filter
        if ($request->has('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->has('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        // Sorting
        $sortBy = $request->get('sort_by', 'created_at');
        $sortDirection = $request->get('sort_direction', 'desc');
        $query->orderBy($sortBy, $sortDirection);

        $perPage = $request->get('per_page', 15);
        $messages = $query->paginate($perPage);

        return response()->json($messages);
    }

    /**
     * Store a newly created contact message (public endpoint)
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'phone' => 'required|string|max:20',
            'name' => 'required|string|max:255',
            'message' => 'required|string|max:5000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        // Clean phone number
        $phone = preg_replace('/\D+/', '', $request->phone);

        $contactMessage = ContactMessage::create([
            'phone' => $phone,
            'name' => $request->name,
            'message' => $request->message,
            'status' => 'new',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Your message has been sent successfully. We will contact you soon.',
            'data' => $contactMessage
        ], 201);
    }

    /**
     * Display the specified contact message
     */
    public function show($id)
    {
        $message = ContactMessage::with(['repliedBy'])->findOrFail($id);

        // Mark as read if it's new
        if ($message->status === 'new') {
            $message->update(['status' => 'read']);
        }

        return response()->json([
            'success' => true,
            'data' => $message
        ]);
    }

    /**
     * Update the specified contact message (for admin reply)
     */
    public function update(Request $request, $id)
    {
        $message = ContactMessage::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'status' => 'sometimes|in:new,read,replied,archived',
            'admin_reply' => 'nullable|string|max:5000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $data = $request->only(['status', 'admin_reply']);

        // If admin_reply is provided, mark as replied
        if ($request->filled('admin_reply')) {
            $data['status'] = 'replied';
            $data['replied_at'] = now();
            $data['replied_by'] = Auth::id();
        }

        $message->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Message updated successfully',
            'data' => $message->load('repliedBy')
        ]);
    }

    /**
     * Remove the specified contact message (soft delete)
     */
    public function destroy($id)
    {
        $message = ContactMessage::findOrFail($id);
        $message->delete();

        return response()->json([
            'success' => true,
            'message' => 'Message deleted successfully'
        ]);
    }

    /**
     * Get all messages from a specific phone number
     */
    public function getByPhone(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'phone' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $phone = $request->phone;
        $cleanPhone = preg_replace('/\D+/', '', $phone);

        $messages = ContactMessage::where('phone', $cleanPhone)
            ->orWhere('phone', $phone)
            ->with(['repliedBy'])
            ->orderByDesc('created_at')
            ->paginate($request->get('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => $messages
        ]);
    }

    /**
     * Get contact message statistics
     */
    public function getStatistics()
    {
        $stats = [
            'total_messages' => ContactMessage::count(),
            'new_messages' => ContactMessage::where('status', 'new')->count(),
            'read_messages' => ContactMessage::where('status', 'read')->count(),
            'replied_messages' => ContactMessage::where('status', 'replied')->count(),
            'archived_messages' => ContactMessage::where('status', 'archived')->count(),
            'today_messages' => ContactMessage::whereDate('created_at', today())->count(),
            'this_week_messages' => ContactMessage::whereBetween('created_at', [
                now()->startOfWeek(),
                now()->endOfWeek()
            ])->count(),
            'this_month_messages' => ContactMessage::whereMonth('created_at', now()->month)
                ->whereYear('created_at', now()->year)
                ->count(),
        ];

        return response()->json([
            'success' => true,
            'data' => $stats
        ]);
    }

    /**
     * Bulk update status
     */
    public function bulkUpdateStatus(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'message_ids' => 'required|array',
            'message_ids.*' => 'exists:contact_messages,id',
            'status' => 'required|in:new,read,replied,archived',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        ContactMessage::whereIn('id', $request->message_ids)
            ->update(['status' => $request->status]);

        return response()->json([
            'success' => true,
            'message' => 'Messages updated successfully'
        ]);
    }
}
