<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SupportTicket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupportTicketController extends Controller
{
    private const ALLOWED_STATUSES = ['open', 'in_progress', 'resolved', 'closed'];

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = SupportTicket::query()->with(['booking']);

        if ($user->role !== 'admin') {
            $query->where('user_id', $user->id);
        }

        $tickets = $query
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 15));

        return response()->json($tickets);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'subject' => 'required|string|max:255',
            'message' => 'required|string',
            'booking_id' => 'nullable|uuid|exists:bookings,id',
        ]);

        $ticket = SupportTicket::create([
            'user_id' => $request->user()->id,
            'booking_id' => $data['booking_id'] ?? null,
            'subject' => $data['subject'],
            'message' => $data['message'],
            'status' => 'open',
        ]);

        return response()->json([
            'message' => 'Support ticket created successfully.',
            'ticket' => $ticket->fresh(['booking']),
        ], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $ticket = SupportTicket::with(['booking'])->findOrFail($id);
        $user = $request->user();

        if ($user->role !== 'admin' && $ticket->user_id !== $user->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return response()->json($ticket);
    }

    public function updateStatus(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'status' => 'required|string|in:' . implode(',', self::ALLOWED_STATUSES),
        ]);

        $ticket = SupportTicket::findOrFail($id);
        $ticket->status = $request->input('status');
        $ticket->save();

        return response()->json([
            'message' => 'Status updated.',
            'ticket' => $ticket,
        ]);
    }
}

