<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WhatsAppNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WhatsAppWorkerController extends Controller
{
    private function authorizeWorker(Request $request): void
    {
        $expected = (string) config('services.whatsapp_worker.secret');
        $received = (string) $request->header('X-Worker-Token', '');

        abort_if(
            $expected === '' ||
            $received === '' ||
            !hash_equals($expected, $received),
            403,
            'Invalid worker token.'
        );
    }

    public function claim(Request $request): JsonResponse
    {
        $this->authorizeWorker($request);

        $notification = DB::transaction(function () {
            $now = now();

            $item = WhatsAppNotification::query()
                ->where('attempts', '<', 5)
                ->where(function ($query) use ($now) {
                    $query
                        ->where(function ($q) use ($now) {
                            $q->where('status', 'pending')
                                ->where(function ($date) use ($now) {
                                    $date->whereNull('next_attempt_at')
                                        ->orWhere(
                                            'next_attempt_at',
                                            '<=',
                                            $now
                                        );
                                });
                        })
                        ->orWhere(function ($q) use ($now) {
                            $q->where('status', 'processing')
                                ->where(
                                    'reserved_until',
                                    '<=',
                                    $now
                                );
                        });
                })
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if (!$item) {
                return null;
            }

            $item->update([
                'status' => 'processing',
                'attempts' => $item->attempts + 1,
                'reserved_until' => now()->addMinutes(5),
                'next_attempt_at' => null,
                'last_error' => null,
            ]);

            return $item->fresh();
        }, 3);

        return response()->json([
            'success' => true,
            'data' => $notification
                ? [
                    'id' => $notification->id,
                    'event_key' => $notification->event_key,
                    'message' => $notification->message,
                    'attempts' => $notification->attempts,
                    'reserved_until' =>
                        $notification->reserved_until?->toIso8601String(),
                ]
                : null,
        ]);
    }

    public function sent(
        Request $request,
        WhatsAppNotification $notification
    ): JsonResponse {
        $this->authorizeWorker($request);

        $validated = $request->validate([
            'attempts' => ['required', 'integer', 'min:1'],
        ]);

        $updated = WhatsAppNotification::query()
            ->whereKey($notification->id)
            ->where('status', 'processing')
            ->where('attempts', $validated['attempts'])
            ->where('reserved_until', '>', now())
            ->update([
                'status' => 'sent',
                'sent_at' => now(),
                'reserved_until' => null,
                'next_attempt_at' => null,
                'last_error' => null,
                'updated_at' => now(),
            ]);

        return response()->json([
            'success' => $updated === 1,
            'message' => $updated
                ? 'Notification marked sent.'
                : 'Notification lease is no longer valid.',
        ], $updated ? 200 : 409);
    }

    public function failed(
        Request $request,
        WhatsAppNotification $notification
    ): JsonResponse {
        $this->authorizeWorker($request);

        $validated = $request->validate([
            'attempts' => ['required', 'integer', 'min:1'],
            'error' => ['required', 'string', 'max:2000'],
        ]);

        $result = DB::transaction(function () use (
            $notification,
            $validated
        ) {
            $item = WhatsAppNotification::query()
                ->whereKey($notification->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (
                $item->status !== 'processing' ||
                $item->attempts !== $validated['attempts'] ||
                !$item->reserved_until ||
                $item->reserved_until->isPast()
            ) {
                return false;
            }

            $exhausted = $item->attempts >= 5;

            $item->update([
                'status' => $exhausted ? 'failed' : 'pending',
                'reserved_until' => null,
                'next_attempt_at' => $exhausted
                    ? null
                    : now()->addSeconds(
                        min(300, 30 * (2 ** ($item->attempts - 1)))
                    ),
                'last_error' => $validated['error'],
            ]);

            return true;
        }, 3);

        return response()->json([
            'success' => $result,
        ], $result ? 200 : 409);
    }
}
