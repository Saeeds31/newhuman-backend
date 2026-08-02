<?php

namespace Modules\Front\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\Banners\Models\Banner;
use Modules\Categories\Models\Category;
use Modules\Menus\Models\Menu;
use Modules\Products\Models\Product;
use Modules\Settings\Models\Setting;

class FrontController extends Controller
{
    public function getCategories()
    {
        $categories = Category::with('children')
            ->whereNull('parent_id')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'دسته بندی ها به صورت درختی',
            'data'    => $categories
        ]);
    }
    public function getBanners()
    {
        $categories = Banner::latest()
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'بنرها',
            'data'    => $categories
        ]);
    }
    public function getMenus()
    {
        $menus = Menu::with('children')
            ->whereNull('parent_id')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'منو ها',
            'data'    => $menus
        ]);
    }
    public function getPodcast()
    {
        $podcasts = Product::with(['productType', 'images'])
            ->where('product_type_id', 2)
            ->published()
            ->latest()->paginate(12);

        return response()->json([
            'success' => true,
            'message' => 'پادکست ها',
            'data'    => $podcasts
        ]);
    }
    public function getSettings()
    {
        $settings = Setting::all()
            ->groupBy('group')
            ->map(function ($group) {
                return $group->mapWithKeys(function ($setting) {
                    return [$setting->key => $setting->value];
                })->toArray();
            });
        return response()->json([
            'success' => true,
            'message' => 'تنظیمات',
            'data'    => $settings
        ]);
    }
}
