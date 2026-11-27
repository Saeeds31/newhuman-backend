<?php

namespace Modules\Orders\Http\Controllers;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Orders\Models\LearningActivity;
use Modules\Orders\Models\LessonProgress;
use Modules\Orders\Models\Order;
use Modules\Products\Models\Product;
use Modules\Users\Models\User;

class LearningProgressController extends Controller
{
    /**
     * تشخیص نوع مدل آموزشی محصول (ضبط‌شده، وبینار، آنلاین زنده، بوت‌کمپ حضوری، کتاب)
     */
    private function detectProgramMode(?Product $product): array
    {
        if (!$product) {
            return ['code' => 'recorded', 'label' => 'دوره ضبط‌شده'];
        }

        $childType = strtolower((string) ($product->child_type ?? ''));
        $title = (string) ($product->title ?? $product->name ?? '');

        if (!empty($product->book_meta) || str_contains($title, 'کتاب')) {
            return ['code' => 'book', 'label' => 'کتاب'];
        }

        if ($childType === 'in_person' || (!empty($product->location) && empty($product->meeting_link)) || str_contains($title, 'بوت‌کمپ') || str_contains($title, 'حضوری')) {
            return ['code' => 'in_person', 'label' => 'بوت‌کمپ حضوری'];
        }

        if (str_contains($title, 'وبینار')) {
            return ['code' => 'webinar', 'label' => 'وبینار آنلاین'];
        }

        if ($childType === 'online' || !empty($product->meeting_link) || str_contains($title, 'آنلاین')) {
            return ['code' => 'online', 'label' => 'دوره آنلاین زنده'];
        }

        return ['code' => 'recorded', 'label' => 'دوره ضبط‌شده'];
    }

    /**
     * محاسبه امتیاز سلامت رشد (0 تا 100)، سطح ریسک ریزش و پیشنهاد اقدام کوچینگ
     */
    private function calculateGrowthMetrics(
        string $programCode,
        int $percentage,
        int $watchedMinutes,
        int $completedEpisodes,
        int $daysSincePurchase,
        ?int $daysSinceActivity,
        int $rewatchCount,
        int $attendanceCount,
        int $coachNotesCount
    ): array {
        $score = 0;

        if ($programCode === 'in_person' || $programCode === 'webinar') {
            // وزن‌دهی ویژه حضور و تعامل برای وبینار و بوت‌کمپ حضوری
            $attendanceScore = $attendanceCount > 0 ? 45 : ($daysSincePurchase <= 7 ? 20 : 5);
            $replayScore = min(35, (int) round($percentage * 0.35));
            $followUpScore = min(20, ($coachNotesCount * 7) + ($rewatchCount * 4));
            $score = min(100, $attendanceScore + $replayScore + $followUpScore);
        } else {
            // وزن‌دهی برای دوره‌های ضبط‌شده و آنلاین
            $progressScore = (int) round($percentage * 0.50); // حداکثر 50 امتیاز
            $recencyScore = 0;
            if ($daysSinceActivity !== null) {
                if ($daysSinceActivity <= 2) {
                    $recencyScore = 30;
                } elseif ($daysSinceActivity <= 5) {
                    $recencyScore = 24;
                } elseif ($daysSinceActivity <= 10) {
                    $recencyScore = 15;
                } elseif ($daysSinceActivity <= 20) {
                    $recencyScore = 7;
                }
            } elseif ($daysSincePurchase <= 3) {
                $recencyScore = 15;
            }

            $engagementScore = min(20, ($completedEpisodes * 2) + ($rewatchCount * 3) + ($coachNotesCount * 4));
            $score = min(100, $progressScore + $recencyScore + $engagementScore);
        }

        if ($percentage >= 100) {
            $score = max($score, 92);
        }

        if ($score >= 75) {
            $riskLevel = 'low';
            $riskLabel = 'رشد عالی (کم‌ریسک)';
            $recommendedAction = $percentage >= 100
                ? 'پیشنهاد ورود به سطح بعدی کوچینگ یا بوت‌کمپ تخصصی'
                : 'ارسال پیام تشویقی و معرفی تمرین تکمیلی';
        } elseif ($score >= 45) {
            $riskLevel = 'medium';
            $riskLabel = 'نرمال (نیازمند حفظ ریتم)';
            $recommendedAction = 'ارسال یادآور هفتگی و بررسی چالش‌های احتمالی در جلسات';
        } elseif ($score >= 25) {
            $riskLevel = 'high';
            $riskLabel = 'هشدار افت سرعت';
            $recommendedAction = 'تماس یا پیام پیگیری منتور برای رفع مانع توقف';
        } else {
            $riskLevel = 'critical';
            $riskLabel = 'ریسک بالای ریزش';
            $recommendedAction = 'اقدام فوری کوچ: تماس تلفنی و تعیین برنامه اقدام کوتاه‌مدت';
        }

        return [
            'growth_score'       => $score,
            'risk_level'         => $riskLevel,
            'risk_label'         => $riskLabel,
            'recommended_action' => $recommendedAction,
        ];
    }

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
            'session_delta'       => 'nullable|integer|min:0',
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
                'meta'        => [
                    'episode_id' => $data['episode_id'],
                    'hour'       => (int) $now->format('H'),
                ],
            ]);
        }

        $existing = LessonProgress::where('user_id', $user->id)
            ->where('product_id', $data['product_id'])
            ->where('episode_id', $data['episode_id'])
            ->first();

        $wasCompleted = $existing?->is_completed ?? false;
        $prevWatched = (int) ($existing?->watched_seconds ?? 0);
        $incomingWatched = (int) ($data['watched_seconds'] ?? 0);
        $deltaSeconds = isset($data['session_delta'])
            ? (int) $data['session_delta']
            : max(0, $incomingWatched - $prevWatched);

        $newWatched = max($prevWatched, $incomingWatched);

        $record = LessonProgress::updateOrCreate(
            [
                'user_id'    => $user->id,
                'product_id' => $data['product_id'],
                'episode_id' => $data['episode_id'],
            ],
            [
                'is_completed'        => $wasCompleted || $data['is_completed'],
                'watched_seconds'     => $newWatched,
                'progress_percentage' => max((int) ($existing?->progress_percentage ?? 0), (int) ($data['progress_percentage'] ?? 0)),
                'last_watched_at'     => $now,
            ]
        );

        $title = $data['episode_title'] ?? "جلسه #{$data['episode_id']}";

        // ثبت پالس مطالعه برای نمودار ضربان روزانه و ساعات مطالعه (هر زمان حداقل 25 ثانیه جدید تماشا شده باشد)
        if ($deltaSeconds >= 25) {
            LearningActivity::create([
                'user_id'     => $user->id,
                'product_id'  => $data['product_id'],
                'episode_id'  => $data['episode_id'],
                'action_type' => 'study_pulse',
                'description' => "مطالعه {$title}",
                'meta'        => [
                    'delta_seconds'       => $deltaSeconds,
                    'episode_title'       => $title,
                    'progress_percentage' => $record->progress_percentage,
                    'hour'                => (int) $now->format('H'),
                ],
            ]);
        }

        // تشخیص بازبینی مجدد جلسه‌ای که قبلاً تکمیل شده است
        if ($wasCompleted && $deltaSeconds >= 30) {
            $recentRewatch = LearningActivity::where('user_id', $user->id)
                ->where('product_id', $data['product_id'])
                ->where('episode_id', $data['episode_id'])
                ->where('action_type', 'rewatched_episode')
                ->where('created_at', '>=', $now->copy()->subHours(6))
                ->exists();

            if (!$recentRewatch) {
                LearningActivity::create([
                    'user_id'     => $user->id,
                    'product_id'  => $data['product_id'],
                    'episode_id'  => $data['episode_id'],
                    'action_type' => 'rewatched_episode',
                    'description' => "بازبینی مجدد {$title}",
                    'meta'        => [
                        'episode_title'   => $title,
                        'watched_seconds' => $newWatched,
                        'hour'            => (int) $now->format('H'),
                    ],
                ]);
            }
        }

        if (!$wasCompleted && $data['is_completed']) {
            LearningActivity::create([
                'user_id'     => $user->id,
                'product_id'  => $data['product_id'],
                'episode_id'  => $data['episode_id'],
                'action_type' => 'completed_episode',
                'description' => "تکمیل {$title}",
                'meta'        => [
                    'episode_title'       => $title,
                    'progress_percentage' => $record->progress_percentage,
                    'watched_seconds'     => $newWatched,
                    'hour'                => (int) $now->format('H'),
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
     * ثبت رویدادهای تعاملی دانشجو (ورود به لینک وبینار، توقف ویدیو، دانلود جزوه، ارسال تمرین)
     * POST /api/v1/front/learning/event
     */
    public function recordActivityEvent(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'product_id'  => 'required|integer',
            'episode_id'  => 'nullable|string|max:100',
            'action_type' => 'required|string|in:joined_webinar,paused_video,downloaded_resource,submitted_exercise,note_taken',
            'description' => 'nullable|string|max:255',
            'meta'        => 'nullable|array',
        ]);

        $defaultDescriptions = [
            'joined_webinar'      => 'ورود به جلسه آنلاین / وبینار',
            'paused_video'        => 'توقف و تمرکز روی بخشی از ویدیو',
            'downloaded_resource' => 'دانلود فایل یا جزوه آموزشی',
            'submitted_exercise'  => 'ارسال تمرین / کاربرگ رشد',
            'note_taken'          => 'ثبت یادداشت شخصی در حین یادگیری',
        ];

        $now = Carbon::now();
        $meta = array_merge($data['meta'] ?? [], [
            'hour' => (int) $now->format('H'),
        ]);

        $activity = LearningActivity::create([
            'user_id'     => $user->id,
            'product_id'  => $data['product_id'],
            'episode_id'  => $data['episode_id'] ?? null,
            'action_type' => $data['action_type'],
            'description' => $data['description'] ?? $defaultDescriptions[$data['action_type']],
            'meta'        => $meta,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'رویداد رفتاری ثبت شد',
            'data'    => $activity,
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
     * پنل ادمین: داشبورد کلان تحلیل رفتار، روند مطالعه کل و گلوگاه‌های آموزشی
     * GET /api/v1/admin/learning/analytics
     */
    public function adminDashboardAnalytics(Request $request)
    {
        $now = Carbon::now();
        $startDate = $now->copy()->subDays(13)->startOfDay();

        // روند مطالعه 14 روز اخیر کل دانشجویان
        $recentActivities = LearningActivity::where('created_at', '>=', $startDate)->get();

        $dailyTrend = [];
        for ($i = 13; $i >= 0; $i--) {
            $day = $now->copy()->subDays($i)->format('Y-m-d');
            $dayRows = $recentActivities->filter(fn($a) => $a->created_at->format('Y-m-d') === $day);

            $studySeconds = $dayRows->sum(fn($a) => (int) ($a->meta['delta_seconds'] ?? 0));
            $completions = $dayRows->where('action_type', 'completed_episode')->count();
            $webinarJoins = $dayRows->where('action_type', 'joined_webinar')->count();

            $dailyTrend[] = [
                'date'          => $day,
                'study_minutes' => (int) round($studySeconds / 60),
                'completions'   => $completions,
                'webinar_joins' => $webinarJoins,
                'active_users'  => $dayRows->pluck('user_id')->unique()->count(),
            ];
        }

        // پرتکرارترین جلسات بازبینی‌شده (نشان‌دهنده مبحث چالش‌برانگیز یا کلیدی)
        $topRewatched = LearningActivity::with('product')
            ->where('action_type', 'rewatched_episode')
            ->select('product_id', 'episode_id', DB::raw('COUNT(*) as rewatch_total'))
            ->groupBy('product_id', 'episode_id')
            ->orderByDesc('rewatch_total')
            ->limit(5)
            ->get()
            ->map(fn($row) => [
                'product_id'    => $row->product_id,
                'product_title' => $row->product?->title ?? 'دوره',
                'episode_id'    => $row->episode_id,
                'rewatch_total' => (int) $row->rewatch_total,
            ]);

        return response()->json([
            'success'       => true,
            'daily_trend'   => $dailyTrend,
            'top_rewatched' => $topRewatched,
        ]);
    }

    /**
     * پنل ادمین: لیست پایش دانشجویان، امتیاز سلامت رشد، تفکیک نوع برنامه و ریسک ریزش
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

        // تجمیع شمارش رویدادهای کلیدی (بازبینی، حضور وبینار/بوت‌کمپ، یادداشت کوچ)
        $activityCounts = LearningActivity::select(
            'user_id',
            'product_id',
            'action_type',
            DB::raw('COUNT(*) as total'),
            DB::raw('MAX(created_at) as latest_at')
        )
            ->groupBy('user_id', 'product_id', 'action_type')
            ->get()
            ->groupBy(fn($row) => $row->user_id . '-' . $row->product_id);

        $now = Carbon::now();
        $statusFilter = $request->get('status');
        $programFilter = $request->get('program_type');

        $summary = [
            'total_enrollments'  => 0,
            'active_count'       => 0,
            'slow_count'         => 0,
            'inactive_count'     => 0,
            'completed_count'    => 0,
            'not_started_count'  => 0,
            'high_risk_count'    => 0,
            'average_progress'   => 0,
            'average_growth_score' => 0,
        ];

        $progressSum = 0;
        $growthScoreSum = 0;
        $studentsList = [];

        foreach ($uniqueEnrollments as $order) {
            if (!$order->user || !$order->product) {
                continue;
            }

            $programMode = $this->detectProgramMode($order->product);
            if ($programFilter && $programMode['code'] !== $programFilter) {
                continue;
            }

            $key = $order->user_id . '-' . $order->product_id;
            $prog = $allProgress->get($key);
            $actGroup = $activityCounts->get($key, collect());

            $rewatchCount = (int) ($actGroup->firstWhere('action_type', 'rewatched_episode')?->total ?? 0);
            $attendanceCount = (int) $actGroup->whereIn('action_type', ['joined_webinar', 'attended_bootcamp', 'attended_online_session'])->sum('total');
            $coachNotesCount = (int) $actGroup->whereIn('action_type', ['coach_note', 'action_plan_assigned', 'bootcamp_evaluation'])->sum('total');

            $latestActivityFromEvents = $actGroup->max('latest_at');

            $percentage = (int) ($prog?->max_progress ?? 0);
            $completedCount = (int) ($prog?->completed_count ?? 0);
            $watchedMinutes = (int) round(($prog?->total_watched_seconds ?? 0) / 60);
            $purchasedAt = $order->created_at;
            $daysSincePurchase = $purchasedAt ? $purchasedAt->diffInDays($now) : 0;

            $lastActivityRaw = $prog?->last_activity_at ?? $latestActivityFromEvents;
            $lastActivity = $lastActivityRaw ? Carbon::parse($lastActivityRaw) : null;
            $daysSinceActivity = $lastActivity ? $lastActivity->diffInDays($now) : null;

            if ($percentage >= 100 || ($programMode['code'] === 'in_person' && $attendanceCount > 0)) {
                $statusCode = 'completed';
                $statusLabel = $programMode['code'] === 'in_person' ? 'حضور ثبت‌شده' : 'تکمیل‌شده';
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

            $growthMetrics = $this->calculateGrowthMetrics(
                $programMode['code'],
                $percentage,
                $watchedMinutes,
                $completedCount,
                $daysSincePurchase,
                $daysSinceActivity,
                $rewatchCount,
                $attendanceCount,
                $coachNotesCount
            );

            $summary['total_enrollments']++;
            $summary[$statusCode . '_count']++;
            if (in_array($growthMetrics['risk_level'], ['high', 'critical'], true)) {
                $summary['high_risk_count']++;
            }
            $progressSum += $percentage;
            $growthScoreSum += $growthMetrics['growth_score'];

            if ($statusFilter) {
                if ($statusFilter === 'high_risk' && !in_array($growthMetrics['risk_level'], ['high', 'critical'], true)) {
                    continue;
                } elseif ($statusFilter !== 'high_risk' && $statusFilter !== $statusCode) {
                    continue;
                }
            }

            $studentsList[] = [
                'user_id'              => $order->user->id,
                'full_name'            => $order->user->full_name ?? 'کاربر بدون نام',
                'mobile'               => $order->user->mobile,
                'product_id'           => $order->product->id,
                'product_title'        => $order->product->title ?? $order->product->name,
                'program_type'         => $programMode['code'],
                'program_type_label'   => $programMode['label'],
                'purchased_at'         => $purchasedAt?->toIso8601String(),
                'days_since_purchase'  => $daysSincePurchase,
                'progress_percentage'  => $percentage,
                'completed_episodes'   => $completedCount,
                'watched_minutes'      => $watchedMinutes,
                'rewatch_count'        => $rewatchCount,
                'attendance_count'     => $attendanceCount,
                'coach_notes_count'    => $coachNotesCount,
                'last_activity_at'     => $lastActivity?->toIso8601String(),
                'days_since_activity'  => $daysSinceActivity,
                'status_code'          => $statusCode,
                'status_label'         => $statusLabel,
                'growth_score'         => $growthMetrics['growth_score'],
                'risk_level'           => $growthMetrics['risk_level'],
                'risk_label'           => $growthMetrics['risk_label'],
                'recommended_action'   => $growthMetrics['recommended_action'],
            ];
        }

        if ($summary['total_enrollments'] > 0) {
            $summary['average_progress'] = round($progressSum / $summary['total_enrollments'], 1);
            $summary['average_growth_score'] = round($growthScoreSum / $summary['total_enrollments'], 1);
        }

        return response()->json([
            'success' => true,
            'summary' => $summary,
            'data'    => $studentsList,
        ]);
    }

    /**
     * پنل ادمین: گزارش ۳۶۰ درجه، نمودارهای چندبعدی و پرونده کوچینگ یک دانشجو
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
            $programMode = $this->detectProgramMode($order->product);

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

            // ۱. سری نمودار روند پیشرفت
            $chartSeries = [
                [
                    'date'       => $order->created_at?->format('Y-m-d'),
                    'percentage' => 0,
                    'event'      => 'ثبت‌نام / خرید',
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

            // ۲. نمودار ضربان مطالعه ۱۴ روز اخیر این دانشجو
            $dailyPulse = [];
            for ($i = 13; $i >= 0; $i--) {
                $dayStr = $now->copy()->subDays($i)->format('Y-m-d');
                $dayActs = $activities->filter(fn($a) => $a->created_at->format('Y-m-d') === $dayStr);
                $mins = (int) round($dayActs->sum(fn($a) => (int) ($a->meta['delta_seconds'] ?? 0)) / 60);
                if ($mins === 0 && $dayActs->count() > 0) {
                    $mins = $dayActs->count() * 5;
                }
                $dailyPulse[] = [
                    'date'    => $dayStr,
                    'minutes' => $mins,
                    'events'  => $dayActs->count(),
                ];
            }

            // ۳. توزیع ساعات مطالعه (صبح، عصر، شب، نیمه‌شب)
            $timeBuckets = [
                'morning'   => 0, // 06 تا 12
                'afternoon' => 0, // 12 تا 18
                'evening'   => 0, // 18 تا 24
                'night'     => 0, // 00 تا 06
            ];

            foreach ($activities as $act) {
                $hour = isset($act->meta['hour'])
                    ? (int) $act->meta['hour']
                    : (int) $act->created_at->format('H');

                if ($hour >= 6 && $hour < 12) {
                    $timeBuckets['morning']++;
                } elseif ($hour >= 12 && $hour < 18) {
                    $timeBuckets['afternoon']++;
                } elseif ($hour >= 18 && $hour < 24) {
                    $timeBuckets['evening']++;
                } else {
                    $timeBuckets['night']++;
                }
            }

            // ۴. تحلیل ریز جلسات (درصد، دقیقه و دفعات بازبینی هر جلسه)
            $rewatchByEpisode = $activities
                ->where('action_type', 'rewatched_episode')
                ->groupBy('episode_id')
                ->map->count();

            $titleByEpisode = $activities
                ->filter(fn($a) => !empty($a->episode_id) && !empty($a->meta['episode_title']))
                ->groupBy('episode_id')
                ->map(fn($grp) => $grp->last()->meta['episode_title']);

            $episodesBreakdown = $progressRows->map(function ($row) use ($rewatchByEpisode, $titleByEpisode) {
                return [
                    'episode_id'      => $row->episode_id,
                    'episode_title'   => $titleByEpisode->get($row->episode_id) ?? "جلسه #{$row->episode_id}",
                    'is_completed'    => (bool) $row->is_completed,
                    'watched_minutes' => (int) round(($row->watched_seconds ?? 0) / 60),
                    'rewatch_count'   => (int) ($rewatchByEpisode->get($row->episode_id) ?? 0),
                    'last_watched_at' => $row->last_watched_at?->toIso8601String(),
                ];
            })->values();

            // ۵. یادداشت‌ها، برنامه اقدام و سوابق حضور ثبت‌شده توسط کوچ/منتور
            $coachingNotes = $activities
                ->whereIn('action_type', ['coach_note', 'action_plan_assigned', 'attended_bootcamp', 'attended_online_session', 'bootcamp_evaluation'])
                ->sortByDesc('created_at')
                ->values()
                ->map(fn($a) => [
                    'id'          => $a->id,
                    'action_type' => $a->action_type,
                    'description' => $a->description,
                    'meta'        => $a->meta,
                    'created_at'  => $a->created_at->toIso8601String(),
                ]);

            $lastActivity = $progressRows->max('last_watched_at')
                ? Carbon::parse($progressRows->max('last_watched_at'))
                : ($activities->last()?->created_at);

            $growthMetrics = $this->calculateGrowthMetrics(
                $programMode['code'],
                $percentage,
                $watchedMinutes,
                $progressRows->where('is_completed', true)->count(),
                $order->created_at ? $order->created_at->diffInDays($now) : 0,
                $lastActivity ? $lastActivity->diffInDays($now) : null,
                (int) $rewatchByEpisode->sum(),
                $activities->whereIn('action_type', ['joined_webinar', 'attended_bootcamp', 'attended_online_session'])->count(),
                $coachingNotes->count()
            );

            $coursesReport[] = [
                'product_id'          => $productId,
                'product_title'       => $order->product->title ?? $order->product->name,
                'program_type'        => $programMode['code'],
                'program_type_label'  => $programMode['label'],
                'purchased_at'        => $order->created_at?->toIso8601String(),
                'days_since_purchase' => $order->created_at ? $order->created_at->diffInDays($now) : 0,
                'progress_percentage' => $percentage,
                'cohort_average'      => round($cohortAverage, 1),
                'completed_episodes'  => $progressRows->where('is_completed', true)->count(),
                'watched_minutes'     => $watchedMinutes,
                'growth_score'        => $growthMetrics['growth_score'],
                'risk_level'          => $growthMetrics['risk_level'],
                'risk_label'          => $growthMetrics['risk_label'],
                'recommended_action'  => $growthMetrics['recommended_action'],
                'chart_series'        => $chartSeries,
                'daily_pulse'         => $dailyPulse,
                'time_buckets'        => $timeBuckets,
                'episodes_breakdown'  => $episodesBreakdown,
                'coaching_notes'      => $coachingNotes,
                'timeline'            => $activities
                    ->where('action_type', '!=', 'study_pulse')
                    ->sortByDesc('created_at')
                    ->values()
                    ->map(fn($a) => [
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
                'id'               => $user->id,
                'full_name'        => $user->full_name ?? 'کاربر',
                'mobile'           => $user->mobile,
                'total_programs'   => count($coursesReport),
                'avg_growth_score' => count($coursesReport) > 0
                    ? round(collect($coursesReport)->avg('growth_score'), 1)
                    : 0,
            ],
            'courses' => $coursesReport,
        ]);
    }

    /**
     * پنل ادمین: ثبت یادداشت کوچینگ، برنامه اقدام، حضور در بوت‌کمپ/وبینار یا نمره ارزیابی عملی
     * POST /api/v1/admin/learning/students/{userId}/coaching-action
     */
    public function storeCoachingAction(Request $request, $userId)
    {
        $admin = $request->user();
        $student = User::findOrFail($userId);

        $data = $request->validate([
            'product_id'  => 'required|integer',
            'action_type' => 'required|string|in:coach_note,action_plan_assigned,attended_bootcamp,attended_online_session,bootcamp_evaluation',
            'note'        => 'required|string|max:1000',
            'score'       => 'nullable|integer|min:0|max:100',
            'due_date'    => 'nullable|string|max:50',
        ]);

        $prefixes = [
            'coach_note'              => 'یادداشت کوچینگ',
            'action_plan_assigned'    => 'برنامه اقدام رشد (Action Plan)',
            'attended_bootcamp'       => 'ثبت حضور در بوت‌کمپ حضوری',
            'attended_online_session' => 'ثبت حضور در جلسه آنلاین/وبینار',
            'bootcamp_evaluation'     => 'ارزیابی عملی منتور',
        ];

        $description = $prefixes[$data['action_type']] . ': ' . $data['note'];

        $activity = LearningActivity::create([
            'user_id'     => $student->id,
            'product_id'  => $data['product_id'],
            'action_type' => $data['action_type'],
            'description' => $description,
            'meta'        => [
                'coach_id'   => $admin?->id,
                'coach_name' => $admin?->full_name ?? 'مدیر / کوچ',
                'note'       => $data['note'],
                'score'      => $data['score'] ?? null,
                'due_date'   => $data['due_date'] ?? null,
            ],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'اقدام کوچینگ با موفقیت در پرونده دانشجو ثبت شد.',
            'data'    => $activity,
        ]);
    }
}