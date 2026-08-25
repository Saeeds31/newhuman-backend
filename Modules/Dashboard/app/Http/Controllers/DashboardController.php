<?php

namespace Modules\Dashboard\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Comments\Models\Comment;
use Modules\Orders\Models\Order;
use Modules\Products\Models\Product;
use Modules\Users\Models\User;

class DashboardController extends Controller
{

    public function dashboard()
    {

        return response()->json(
            [
                'message' => 'dashboard content',
                'success' => true,
                'data' => [
                    'orders'   => Order::dashboardReport(),
                    'products' => Product::dashboardReport(),
                    'users'    => User::dashboardReport(),
                    'comments' => Comment::dashboardReport(),
                ]
            ]
        );
    }
     public function uploadImage(Request $request)
    {
        $data = $request->validate([
            'file' => 'required|file|max:1024'
        ]);
        $imagePath = "";
        if ($request->hasFile('file')) {
            $imagePath = $request->file('file')->store('uploads/files/images', 'public');
        }
        return response()->json(['url' => 'https://api.padhosha.ir/storage_public/' . $imagePath]);
    }
}
