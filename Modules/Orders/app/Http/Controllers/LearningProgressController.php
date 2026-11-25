<?php

namespace Modules\Orders\Http\Controllers;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Orders\Models\LearningActivity;
use Modules\Orders\Models\LessonProgress;
use Modules\Orders\Models\Order;
use Modules\Users\Models\User;

class LearningProgressController extends Controller
{
    /**
     * ثبت و همگام‌سازی پیشرفت دانشجو از سمت پلیر
     * POST /api/v1/front/learning/progress
     */
    public function recordProgress(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'product_id'          => 'required|integer',
            'episode_id'          => 'required|string|max:100',
            'is_completed'        => 'required|boolean',
            'progress_percentage' => 'nullable|integer|min:0|max:100',
            'watched_seconds'     => 'nullable|integer|min:0',
            'episode_title'       => 'nullable|string|max:255',
        ]);

        $now = Carbon::now();

        $hasAnyPrior = LessonProgress::where('user_id', $user->id)
            ->where('product_id', $data['product_id'])
            ->exists();

        if (!$hasAnyPrior) {
            LearningActivity::create([
                'user_id'     => $user->id,
                'product_id'  => $data['product_id'],
                'episode_id'  => $data['episode_id'],
                'action_type' => 'started_course',
                'description' => 'شروع یادگیری دوره',
                'meta'        => ['episode_id' => $data['episode_id']],
            ]);
        }

        $existing = LessonProgress::where('user_id', $user->id)
            ->where('product_id', $data['product_id'])
            ->where('episode_id', $data['episode_id'])
            ->first();

        $wasCompleted = $existing?->is_completed ?? false;
        $newWatched = max((int) ($existing?->watched_seconds ?? 0), (int) ($data['watched_seconds'] ?? 0));

        $record = LessonProgress::updateOrCreate(
            [
                'user_id'    => $user->id,
                'product_id' => $data['product_id'],
                'episode_id' => $data['episode_id'],
            ],
            [
                'is_completed'        => $data['is_completed'],
                'watched_seconds'     => $newWatched,
                'progress_percentage' => $data['progress_percentage'] ?? ($existing?->progress_percentage ?? 0),
                'last_watched_at'     => $now,
            ]
        );

        if (!$wasCompleted && $data['is_completed']) {
            $title = $data['episode_title'] ?? "جلسه #{$data['episode_id']}";
            LearningActivity::create([
                'user_id'     => $user->id,
                'product_id'  => $data['product_id'],
                'episode_id'  => $data['episode_id'],
                'action_type' => 'completed_episode',
                'description' => "تکمیل {$title}",
                'meta'        => [
                    'progress_percentage' => $record->progress_percentage,
                    'watched_seconds'     => $newWatched,
                ],
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'پیشرفت با موفقیت ثبت شد',
            'data'    => $record,
        ]);
    }

    /**
     * دریافت وضعیت پیشرفت یک دوره برای کاربر
     * GET /api/v1/front/learning/progress/{productId}
     */
    public function userCourseProgress(Request $request, $productId)
    {
        $user = $request->user();

        $rows = LessonProgress::where('user_id', $user->id)
            ->where('product_id', $productId)
            ->orderByDesc('last_watched_at')
            ->get();

        $completedIds = $rows->where('is_completed', true)->pluck('episode_id')->values();
        $latestRow = $rows->first();

        return response()->json([
            'success'             => true,
            'product_id'          => (int) $productId,
            'completed_episodes'  => $completedIds,
            'progress_percentage' => $latestRow?->progress_percentage ?? 0,
            'last_episode_id'     => $latestRow?->episode_id,
            'last_watched_at'     => $latestRow?->last_watched_at,
        ]);
    }

    /**
     * پنل ادمین: لیست پایش دانشجویان، شاخص‌های وضعیت و میانگین پیشرفت
     * GET /api/v1/admin/learning/students
     */
    public function adminStudentsOverview(Request $request)
    {
        $query = Order::with(['user', 'product'])
            ->where(function ($q) {
                $q->where('payment_status', 'paid')
                    ->orWhereIn('status', ['processing', 'completed']);
            })
            ->orderByDesc('created_at');

        if ($productId = $request->get('product_id')) {
            $query->where('product_id', $productId);
        }

        if ($search = trim((string) $request->get('q', ''))) {
            $query->whereHas('user', function ($q) use ($search) {
                $q->where('full_name', 'like', "%{$search}%")
                    ->orWhere('mobile', 'like', "%{$search}%");
            });
        }

        $orders = $query->get();

        $uniqueEnrollments = $orders->unique(function ($item) {
            return $item->user_id . '-' . $item->product_id;
        })->values();

        $allProgress = LessonProgress::select(
            'user_id',
            'product_id',
            DB::raw('MAX(progress_percentage) as max_progress'),
            DB::raw('SUM(CASE WHEN is_completed = 1 THEN 1 ELSE 0 END) as completed_count'),
            DB::raw('SUM(watched_seconds) as total_watched_seconds'),
            DB::raw('MAX(last_watched_at) as last_activity_at')
        )
            ->groupBy('user_id', 'product_id')
            ->get()
            ->keyBy(fn($row) => $row->user_id . '-' . $row->product_id);

        $now = Carbon::now();
        $statusFilter = $request->get('status');

        $summary = [
            'total_enrollments' => 0,
            'active_count'      => 0,
            'slow_count'        => 0,
            'inactive_count'    => 0,
            'completed_count'   => 0,
            'not_started_count' => 0,
            'average_progress'  => 0,
        ];

        $progressSum = 0;
        $studentsList = [];

        foreach ($uniqueEnrollments as $order) {
            if (!$order->user || !$order->product) {
                continue;
            }

            $key = $order->user_id . '-' . $order->product_id;
            $prog = $allProgress->get($key);

            $percentage = (int) ($prog?->max_progress ?? 0);
            $completedCount = (int) ($prog?->completed_count ?? 0);
            $watchedMinutes = (int) round(($prog?->total_watched_seconds ?? 0) / 60);
            $purchasedAt = $order->created_at;
            $daysSincePurchase = $purchasedAt ? $purchasedAt->diffInDays($now) : 0;

            $lastActivity = $prog?->last_activity_at ? Carbon::parse($prog->last_activity_at) : null;
            $daysSinceActivity = $lastActivity ? $lastActivity->diffInDays($now) : null;

            if ($percentage >= 100) {
                $statusCode = 'completed';
                $statusLabel = 'تکمیل‌شده';
            } elseif (!$lastActivity && $percentage === 0) {
                if ($daysSincePurchase > 7) {
                    $statusCode = 'inactive';
                    $statusLabel = 'رهاکرده (بدون شروع)';
                } else {
                    $statusCode = 'not_started';
                    $statusLabel = 'تازه‌خرید (شروع نشده)';
                }
            } elseif ($daysSinceActivity !== null && $daysSinceActivity <= 7) {
                $statusCode = 'active';
                $statusLabel = 'در حال پیشروی منظم';
            } elseif ($daysSinceActivity !== null && $daysSinceActivity <= 14) {
                $statusCode = 'slow';
                $statusLabel = 'نیازمند پیگیری';
            } else {
                $statusCode = 'inactive';
                $statusLabel = 'متوقف‌شده';
            }

            $summary['total_enrollments']++;
            $summary[$statusCode . '_count']++;
            $progressSum += $percentage;

            if ($statusFilter && $statusFilter !== $statusCode) {
                continue;
            }

            $studentsList[] = [
                'user_id'              => $order->user->id,
                'full_name'            => $order->user->full_name ?? 'کاربر بدون نام',
                'mobile'               => $order->user->mobile,
                'product_id'           => $order->product->id,
                'product_title'        => $order->product->title ?? $order->product->name,
                'purchased_at'         => $purchasedAt?->toIso8601String(),
                'days_since_purchase'  => $daysSincePurchase,
                'progress_percentage'  => $percentage,
                'completed_episodes'   => $completedCount,
                'watched_minutes'      => $watchedMinutes,
                'last_activity_at'     => $lastActivity?->toIso8601String(),
                'days_since_activity'  => $daysSinceActivity,
                'status_code'          => $statusCode,
                'status_label'         => $statusLabel,
            ];
        }

        if ($summary['total_enrollments'] > 0) {
            $summary['average_progress'] = round($progressSum / $summary['total_enrollments'], 1);
        }

        return response()->json([
            'success' => true,
            'summary' => $summary,
            'data'    => $studentsList,
        ]);
    }

    /**
     * پنل ادمین: گزارش جامع، داده‌های نمودار روند پیشرفت و تایم‌لاین اقدامات یک دانشجو
     * GET /api/v1/admin/learning/students/{userId}
     */
    public function adminStudentDetailReport(Request $request, $userId)
    {
        $user = User::findOrFail($userId);

        $orders = Order::with('product')
            ->where('user_id', $user->id)
            ->where(function ($q) {
                $q->where('payment_status', 'paid')
                    ->orWhereIn('status', ['processing', 'completed']);
            })
            ->orderByDesc('created_at')
            ->get()
            ->unique('product_id')
            ->values();

        $coursesReport = [];
        $now = Carbon::now();

        foreach ($orders as $order) {
            if (!$order->product) {
                continue;
            }

            $productId = $order->product_id;

            $progressRows = LessonProgress::where('user_id', $user->id)
                ->where('product_id', $productId)
                ->orderBy('last_watched_at')
                ->get();

            $percentage = (int) ($progressRows->max('progress_percentage') ?? 0);
            $watchedMinutes = (int) round($progressRows->sum('watched_seconds') / 60);

            $cohortAverage = (float) LessonProgress::where('product_id', $productId)
                ->select('user_id', DB::raw('MAX(progress_percentage) as user_max'))
                ->groupBy('user_id')
                ->get()
                ->avg('user_max');

            $activities = LearningActivity::where('user_id', $user->id)
                ->where('product_id', $productId)
                ->orderBy('created_at')
                ->get();

            $chartSeries = [
                [
                    'date'       => $order->created_at?->format('Y-m-d'),
                    'percentage' => 0,
                    'event'      => 'خرید دوره',
                ],
            ];

            foreach ($activities as $act) {
                if (isset($act->meta['progress_percentage'])) {
                    $chartSeries[] = [
                        'date'       => $act->created_at->format('Y-m-d'),
                        'percentage' => (int) $act->meta['progress_percentage'],
                        'event'      => $act->description,
                    ];
                }
            }

            $coursesReport[] = [
                'product_id'          => $productId,
                'product_title'       => $order->product->title ?? $order->product->name,
                'purchased_at'        => $order->created_at?->toIso8601String(),
                'days_since_purchase' => $order->created_at ? $order->created_at->diffInDays($now) : 0,
                'progress_percentage' => $percentage,
                'cohort_average'      => round($cohortAverage, 1),
                'completed_episodes'  => $progressRows->where('is_completed', true)->count(),
                'watched_minutes'     => $watchedMinutes,
                'chart_series'        => $chartSeries,
                'timeline'            => $activities->sortByDesc('created_at')->values()->map(fn($a) => [
                    'id'          => $a->id,
                    'action_type' => $a->action_type,
                    'description' => $a->description,
                    'meta'        => $a->meta,
                    'created_at'  => $a->created_at->toIso8601String(),
                ]),
            ];
        }

        return response()->json([
            'success' => true,
            'student' => [
                'id'        => $user->id,
                'full_name' => $user->full_name ?? 'کاربر',
                'mobile'    => $user->mobile,
            ],
            'courses' => $coursesReport,
        ]);
    }
}